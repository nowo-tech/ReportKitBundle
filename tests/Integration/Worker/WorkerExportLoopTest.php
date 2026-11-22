<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Worker;

use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ReportKitBundle\Tests\Unit\Infrastructure\RenderersTest;
use Psr\Container\ContainerInterface;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function gc_collect_cycles;
use function json_encode;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;
use function scandir;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * Contract §11 / REQ-RUNTIME-002: one kernel kept alive, no services resetter, 500 alternating PDF / XLSX / CSV
 * exports by two owners in two locales. After every call the private temp directory is empty and the translator is
 * back on the default locale; owner A never sees owner B's context; memory drift stays under 4 MiB (Dompdf).
 */
final class WorkerExportLoopTest extends KernelTestCase
{
    private const EXPORTS = 500;

    private const FORMATS = ['pdf', 'xlsx', 'csv'];

    public function testFiveHundredAlternatingExportsLeaveNothingBehind(): void
    {
        $temp = sys_get_temp_dir() . '/nowo-report-kit-worker-temp';
        (new Filesystem())->mkdir($temp);
        $kernel = $this->bootKernel(['enabled' => true, 'temp_dir' => $temp], services: static function (ContainerConfigurator $c): void {
            $c->services()->set(OrdersReportType::class)->autowire()->autoconfigure();
        });
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $tests = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $tests);
        $translator = $tests->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $baseline = 0;
        $parser   = new Parser();
        for ($i = 0; $i < self::EXPORTS; ++$i) {
            $owner    = $i % 2 === 0 ? 'a' : 'b';
            $other    = $owner === 'a' ? 'b1' : 'a1';
            $locale   = $i % 4 < 2 ? 'en' : 'de';
            $format   = self::FORMATS[$i % 3];
            $request  = Request::create('/export/orders/' . $owner . '.' . $format, 'GET', ['locale' => $locale]);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
            $http->terminate($request, $response);

            self::assertSame(200, $response->getStatusCode(), 'export ' . $i);
            $text = match ($format) {
                'pdf'   => $parser->parseContent($body)->getText(),
                'xlsx'  => json_encode(RenderersTest::sheets($body), JSON_THROW_ON_ERROR),
                default => $body,
            };
            self::assertStringContainsString('project:' . ($owner === 'a' ? 'a1' : 'b1'), $text, 'export ' . $i . ' holds its own context');
            self::assertStringNotContainsString('project:' . $other, $text, 'export ' . $i . ' never holds the other owner\'s context');
            self::assertStringContainsString($locale === 'de' ? 'Letzte 30 Tage' : 'Last 30 days', $text, 'export ' . $i . ' is in its locale');
            self::assertSame(['.', '..'], scandir($temp), 'no temporary file left after export ' . $i);
            self::assertSame('en', $translator->getLocale(), 'the translator locale is untouched after export ' . $i);

            if ($i === 50) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::EXPORTS . ' exports');
        self::assertSame([], $GLOBALS['_dompdf_warnings'] ?? [], 'Dompdf warnings do not pile up');
        (new Filesystem())->remove($temp);
    }
}
