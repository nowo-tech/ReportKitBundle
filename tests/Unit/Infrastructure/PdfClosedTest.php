<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use Dompdf\Dompdf;
use Dompdf\Options;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\PdfOptions;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Pdf\ClosedDompdfOptions;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfDocumentRenderer;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfFontCacheWarmer;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfWarnings;
use Nowo\ReportKitBundle\Tests\Fixtures\Rendering;
use Nowo\ReportKitBundle\Tests\Fixtures\SpyStreamWrapper;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Symfony\Component\Filesystem\Filesystem;

use function array_keys;
use function base64_encode;
use function file_exists;
use function file_put_contents;
use function glob;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;

/**
 * Contract §6.2 / audit ZE-I5: the Dompdf options are closed and a hostile template can fetch nothing, read no local
 * file outside the per-call directory and run no PHP or JavaScript.
 */
final class PdfClosedTest extends TestCase
{
    private string $base;

    private string $outside;

    protected function setUp(): void
    {
        $this->base    = sys_get_temp_dir() . '/nowo-report-kit-pdf-closed';
        $this->outside = sys_get_temp_dir() . '/nowo-report-kit-outside';
        (new Filesystem())->mkdir([$this->base, $this->outside]);
        file_put_contents($this->outside . '/leak.css', 'p.probe:after { content: "CSS-LEAK-MARKER"; }');
        file_put_contents($this->outside . '/leak.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="0" y="15">SVG-LEAK-MARKER</text></svg>');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->base, $this->outside, sys_get_temp_dir() . '/nowo-report-kit-php-marker']);
    }

    public function testTheOptionsAreClosed(): void
    {
        $options = ClosedDompdfOptions::create('/private/dir', '/fonts');

        self::assertFalse($options->getIsRemoteEnabled());
        self::assertFalse($options->getIsJavascriptEnabled());
        self::assertFalse($options->getIsPhpEnabled());
        self::assertSame(['data://'], array_keys($options->getAllowedProtocols()));
        self::assertSame(['/private/dir'], $options->getChroot());
        self::assertSame('/private/dir', $options->getTempDir());
        self::assertSame('/fonts', $options->getFontCache());
        self::assertTrue($options->getIsHtml5ParserEnabled());
        self::assertSame('/private/dir', ClosedDompdfOptions::create('/private/dir')->getFontCache());
    }

    public function testAHostileTemplateFetchesNothingReadsNothingRunsNothing(): void
    {
        $outside = $this->outside;
        $marker  = sys_get_temp_dir() . '/nowo-report-kit-php-marker';
        $html    = <<<HTML
            <html><head>
            <link rel="stylesheet" href="http://remote.test/a.css">
            <link rel="stylesheet" href="file://{$outside}/leak.css">
            <link rel="stylesheet" href="{$outside}/leak.css">
            <style>@import url("https://remote.test/b.css"); @font-face { font-family: X; src: url("http://remote.test/f.ttf"); } body { font-family: X, 'DejaVu Sans'; background: url("http://remote.test/bg.png"); }</style>
            </head><body>
            <p class="probe">Visible text</p>
            <img src="http://remote.test/x.png"><img src="https://remote.test/y.png">
            <img src="file://{$outside}/leak.svg"><img src="{$outside}/leak.svg"><img src="../../../../{$outside}/leak.svg">
            <img src="phar://{$outside}/x.phar/a.png">
            <script type="text/php">file_put_contents('{$marker}', 'ran');</script>
            <?php file_put_contents('{$marker}', 'ran'); ?>
            <script type="text/javascript">app.alert('JS-MARKER');</script>
            </body></html>
            HTML;

        SpyStreamWrapper::install();
        try {
            $pdf = $this->renderer(['hostile.html.twig' => $html])->render('hostile.html.twig', []);
        } finally {
            $opened = SpyStreamWrapper::uninstall();
        }
        $text = (new Parser())->parseContent($pdf)->getText();

        self::assertSame([], $opened, 'no http(s) resource was opened');
        self::assertStringContainsString('Visible text', $text);
        self::assertStringNotContainsString('CSS-LEAK-MARKER', $text);
        self::assertStringNotContainsString('SVG-LEAK-MARKER', $text);
        self::assertFalse(file_exists($marker), 'no PHP ran');
        self::assertStringNotContainsString('JS-MARKER', $pdf);
        self::assertStringNotContainsString('/JavaScript', $pdf);
        self::assertSame(['.', '..'], scandir($this->base), 'the private directory is removed');
        self::assertSame([], $GLOBALS['_dompdf_warnings'] ?? [], 'the refused resources left no warning behind (worker memory)');
    }

    /**
     * Positive control: the same stylesheet IS read by an open Dompdf whose chroot holds it, so the test above would
     * see a leak.
     */
    public function testTheLeakProbeWorksAgainstAnOpenDompdf(): void
    {
        $options = new Options();
        $options->setChroot([$this->outside]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<html><head><link rel="stylesheet" href="' . $this->outside . '/leak.css"></head><body><p class="probe">Visible text</p></body></html>');
        $dompdf->render();

        self::assertStringContainsString('CSS-LEAK-MARKER', (new Parser())->parseContent((string) $dompdf->output())->getText());
        DompdfWarnings::clear();
    }

    public function testDataUrisStillLoadAndPageTextsAreDrawn(): void
    {
        $svg  = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="0" y="15">DATA-URI-OK</text></svg>');
        $page = '<div style="page-break-after: always">One</div><div>Two</div><img src="' . $svg . '">';
        $pdf  = $this->renderer(['pages.html.twig' => $page])->render('pages.html.twig', [], new PdfOptions('letter', true, 'Left footer', 'Page {PAGE_NUM} of {PAGE_COUNT}', 'Running header', '#112233'));
        $text = (new Parser())->parseContent($pdf)->getText();

        self::assertStringContainsString('Left footer', $text);
        self::assertStringContainsString('Page 1 of 2', $text);
        self::assertStringContainsString('Page 2 of 2', $text);
        self::assertStringContainsString('Running header', $text);
        self::assertStringContainsString('DATA-URI-OK', $text, 'the data: image is drawn');
    }

    public function testHtmlAboveTheLimitIsRefusedBeforeDompdf(): void
    {
        $renderer = new DompdfDocumentRenderer(Rendering::twig(['big.html.twig' => str_repeat('x', 2048)]), new PrivateTempDirectory($this->base), new RenderLimits(maxPdfHtmlBytes: 1024), $this->base . '/none');

        try {
            $renderer->render('big.html.twig', []);
            self::fail('no limit');
        } catch (ReportLimitExceededException $e) {
            self::assertSame(ReportLimitExceededException::PDF_HTML_BYTES, $e->limit);
        }
        self::assertSame(['.', '..'], scandir($this->base));
    }

    public function testTheFontCacheIsWarmedIntoItsDirectory(): void
    {
        $fonts  = $this->base . '/fonts';
        $warmer = new DompdfFontCacheWarmer(new PrivateTempDirectory($this->outside), $fonts);

        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp($this->base));
        self::assertDirectoryExists($fonts);
        self::assertSame([], glob($this->outside . '/nowo-report-kit-*'));

        $renderer = new DompdfDocumentRenderer(Rendering::twig(['t.html.twig' => 'Hello']), new PrivateTempDirectory($this->base . '/runs'), new RenderLimits(), $fonts);
        (new Filesystem())->mkdir($this->base . '/runs');
        self::assertStringContainsString('Hello', (new Parser())->parseContent($renderer->render('t.html.twig', []))->getText());
    }

    /**
     * @param array<string, string> $templates
     */
    private function renderer(array $templates): DompdfDocumentRenderer
    {
        return new DompdfDocumentRenderer(Rendering::twig($templates), new PrivateTempDirectory($this->base), new RenderLimits(), $this->base . '/missing-font-cache');
    }
}
