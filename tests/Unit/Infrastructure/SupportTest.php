<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use LogicException;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Twig\TemplateCheckWarmer;
use Nowo\ReportKitBundle\Tests\Fixtures\Rendering;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function fileperms;
use function mkdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;

final class SupportTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/nowo-report-kit-support';
        (new Filesystem())->mkdir($this->base . '/keep');
        file_put_contents($this->base . '/keep/file', 'x');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->base);
    }

    public function testThePrivateDirectoryIsRemovedWhateverHappens(): void
    {
        $temp = new PrivateTempDirectory($this->base . '/runs/');
        $dir  = $temp->create();
        self::assertSame(0700, fileperms($dir) & 0777);
        self::assertStringStartsWith($this->base . '/runs/' . PrivateTempDirectory::PREFIX, $dir);
        mkdir($dir . '/sub');
        file_put_contents($dir . '/sub/a', 'a');
        symlink($this->base . '/keep', $dir . '/link');
        $temp->discard($dir);
        self::assertDirectoryDoesNotExist($dir);
        self::assertFileExists($this->base . '/keep/file', 'a symlink is removed, never followed');
        self::assertSame(['.', '..'], scandir($this->base . '/runs'));
        $temp->discard($dir); // already gone (ZG-I4): a no-op, never an exception inside a `finally`

        $system = new PrivateTempDirectory();
        $other  = $system->create();
        self::assertStringStartsWith(sys_get_temp_dir() . '/' . PrivateTempDirectory::PREFIX, $other);
        $system->discard($other);
        self::assertDirectoryDoesNotExist($other);
    }

    public function testAnUnwritableBaseIsAClearError(): void
    {
        file_put_contents($this->base . '/file', 'x');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create a private temporary directory');
        (new PrivateTempDirectory($this->base . '/file'))->create();
    }

    public function testConfiguredTemplatesMustExistZeI4(): void
    {
        $twig = Rendering::twig();
        $ok   = new TemplateCheckWarmer($twig, ['formats.pdf.template' => '@NowoReportKit/pdf/report.html.twig']);
        self::assertFalse($ok->isOptional());
        self::assertSame([], $ok->warmUp('/cache'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"nowo_report_kit.formats.pdf.template" names the template "@NowoReportKit/pdf/typo.html.twig", which does not exist.');
        (new TemplateCheckWarmer($twig, ['formats.pdf.template' => '@NowoReportKit/pdf/typo.html.twig']))->warmUp('/cache');
    }
}
