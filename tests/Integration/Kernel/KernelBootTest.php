<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\HostSettingsProvider;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;

use function str_starts_with;

/**
 * Standard Symfony host: zero configuration registers nothing; `enabled: true` registers the S1 services; the
 * translator and Twig requirements are checked at build.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsWithZeroConfigurationAndIsDisabled(): void
    {
        $container = $this->bootKernel()->getContainer();

        self::assertFalse($container->getParameter('nowo_report_kit.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_report_kit.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_report_kit.table_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
    }

    public function testEnablingTheBundleRegistersTheS1Services(): void
    {
        $container = $this->bootKernel(['enabled' => true, 'api' => ['prefix' => '/v-api']])->getContainer();

        self::assertTrue($container->getParameter('nowo_report_kit.enabled'));
        self::assertSame('/v-api', $container->getParameter('nowo_report_kit.api_prefix'));
        $exporter = $container->get(ReportExporterInterface::class);
        self::assertInstanceOf(ReportExporterInterface::class, $exporter);
        self::assertSame([ReportFormat::Csv, ReportFormat::Xlsx, ReportFormat::Pdf], $exporter->formats());
        self::assertInstanceOf(PdfDocumentRendererInterface::class, $container->get(PdfDocumentRendererInterface::class));
        $warmer   = $this->warmer($container);
        $cacheDir = $container->getParameter('kernel.cache_dir');
        self::assertIsString($cacheDir);
        $warmer->enableOptionalWarmers();
        $warmer->warmUp($cacheDir);
        self::assertDirectoryExists($cacheDir . '/nowo_report_kit/fonts');
    }

    public function testWithoutTwigThePdfTurnsOffOnAuto(): void
    {
        $container = $this->bootKernel(['enabled' => true], twig: false)->getContainer();
        $settings  = $container->get(ReportSettingsProviderInterface::class);

        self::assertInstanceOf(ReportSettingsProviderInterface::class, $settings);
        self::assertSame([ReportFormat::Csv, ReportFormat::Xlsx], $settings->formatsEnabled());
        self::assertFalse($container->has(PdfDocumentRendererInterface::class));
        self::assertSame(['csv', 'xlsx'], $container->getParameter('nowo_report_kit.formats'));
    }

    public function testWithoutTwigAHostSettingsProviderIsLeftAlone(): void
    {
        $container = $this->bootKernel(['enabled' => true, 'settings_provider' => 'app.settings'], services: static function ($c): void {
            $c->services()->set('app.settings', HostSettingsProvider::class);
        }, twig: false)->getContainer();

        self::assertFalse($container->has(PdfDocumentRendererInterface::class));
    }

    public function testWithoutTwigAForcedPdfRefusesToBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"nowo_report_kit.formats.pdf.enabled" is true but Twig is not available');
        $this->bootKernel(['enabled' => true, 'formats' => ['pdf' => ['enabled' => true]]], twig: false);
    }

    public function testATranslatorIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs a translator');
        $this->bootKernel(['enabled' => true], twig: false, translator: false);
    }

    public function testAMissingPdfTemplateStopsTheWarmupZeI4(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('names the template "@NowoReportKit/pdf/missing.html.twig"');
        $container = $this->bootKernel(['enabled' => true, 'formats' => ['pdf' => ['template' => '@NowoReportKit/pdf/missing.html.twig']]])->getContainer();
        $this->warmer($container)->warmUp('/tmp/nowo-report-kit-warmup');
    }

    private function warmer(\Symfony\Component\DependencyInjection\ContainerInterface $container): CacheWarmerAggregate
    {
        $tests = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $tests);
        $warmer = $tests->get('cache_warmer');
        self::assertInstanceOf(CacheWarmerAggregate::class, $warmer);

        return $warmer;
    }

    /**
     * @return list<string>
     */
    private function bundleServiceIds(object $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $ids = [];
        foreach ($container->getServiceIds() as $id) {
            $bundleService = str_starts_with($id, 'nowo_report_kit') || str_starts_with($id, 'Nowo\\ReportKitBundle\\');
            if ($bundleService && !str_starts_with($id, 'Nowo\\ReportKitBundle\\Tests\\')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
