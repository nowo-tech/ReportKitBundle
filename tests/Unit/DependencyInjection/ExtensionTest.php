<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Nowo\ReportKitBundle\Infrastructure\Csv\BuiltInCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Csv\ControllerKitCsvCellGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function array_values;
use function str_starts_with;

final class ExtensionTest extends TestCase
{
    public function testDefaultsPublishTheParametersAndRegisterNoService(): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([], $container);

        self::assertFalse($container->getParameter('nowo_report_kit.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_report_kit.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_report_kit.table_prefix'));
        self::assertIsArray($container->getParameter('nowo_report_kit.config'));
        self::assertSame([], $this->bundleIds($container));
        self::assertFalse($container->hasParameter('nowo_report_kit.formats'));
    }

    public function testHostValuesReachTheParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([
            ['enabled' => true, 'api' => ['enabled' => true, 'prefix' => '/api/v-host'], 'doctrine' => ['table_prefix' => 'app_']],
        ], $container);

        self::assertTrue($container->getParameter('nowo_report_kit.enabled'));
        self::assertSame('/api/v-host', $container->getParameter('nowo_report_kit.api_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_report_kit.table_prefix'));
    }

    public function testEnabledRegistersEveryInstalledFormat(): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['enabled' => true, 'formats' => ['json' => ['enabled' => true]]]], $container);

        self::assertSame(['csv', 'xlsx', 'pdf', 'json'], $container->getParameter('nowo_report_kit.formats'));
        foreach (['csv', 'xlsx', 'pdf', 'json'] as $format) {
            self::assertTrue($container->hasDefinition('nowo_report_kit.renderer.' . $format));
        }
        self::assertSame(ControllerKitCsvCellGuard::class, $container->getDefinition('nowo_report_kit.csv_guard')->getClass());
        self::assertSame(ConfigReportSettingsProvider::class, $container->getDefinition('nowo_report_kit.settings_provider')->getClass());
        self::assertSame(DefaultReportBrandResolver::class, $container->getDefinition('nowo_report_kit.brand_resolver')->getClass());
        self::assertTrue($container->hasAlias(PdfDocumentRendererInterface::class));
        self::assertTrue($container->hasDefinition('nowo_report_kit.template_check_warmer'));
        self::assertSame([ReportTypeInterface::TAG => [[]]], $container->getAutoconfiguredInstanceof()[ReportTypeInterface::class]->getTags());
    }

    public function testFormatsOffAndEnginesMissing(): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension(static fn (string $class): bool => false))->load([[
            'enabled' => true,
            'formats' => ['csv' => ['enabled' => false]],
            'bridges' => ['controller_kit' => 'auto'],
        ]], $container);

        self::assertSame([], $container->getParameter('nowo_report_kit.formats'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.renderer.csv'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.renderer.xlsx'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.renderer.pdf'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.pdf_document_renderer'));

        $builtIn = new ContainerBuilder();
        (new NowoReportKitExtension(static fn (string $class): bool => false))->load([['enabled' => true]], $builtIn);
        self::assertSame(BuiltInCsvCellGuard::class, $builtIn->getDefinition('nowo_report_kit.csv_guard')->getClass());
        self::assertSame(['csv'], $builtIn->getParameter('nowo_report_kit.formats'));

        $off = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['enabled' => true, 'bridges' => ['controller_kit' => false], 'formats' => ['pdf' => ['enabled' => false]]]], $off);
        self::assertSame(BuiltInCsvCellGuard::class, $off->getDefinition('nowo_report_kit.csv_guard')->getClass());
        self::assertFalse($off->hasDefinition('nowo_report_kit.renderer.pdf'));
        self::assertTrue($off->hasDefinition('nowo_report_kit.pdf_document_renderer'), 'Q-O10: the PDF runner stays available');
    }

    public function testControllerKitForcedOnWithoutThePackageRefusesToBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"nowo_report_kit.bridges.controller_kit" is true');
        (new NowoReportKitExtension(static fn (string $class): bool => false))->load([['bridges' => ['controller_kit' => true]]], new ContainerBuilder());
    }

    public function testHostServicesReplaceTheDefaults(): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['enabled' => true, 'settings_provider' => 'app.settings', 'brand' => ['resolver' => 'app.brand']]], $container);

        self::assertSame('app.settings', (string) $container->getAlias('nowo_report_kit.settings_provider'));
        self::assertSame('nowo_report_kit.settings_provider', (string) $container->getAlias(ReportSettingsProviderInterface::class));
        self::assertSame('app.brand', (string) $container->getAlias('nowo_report_kit.brand_resolver'));
        self::assertSame('nowo_report_kit.brand_resolver', (string) $container->getAlias(ReportBrandResolverInterface::class));
    }

    /**
     * @return list<string>
     */
    private function bundleIds(ContainerBuilder $container): array
    {
        return array_values(array_filter(
            [...array_keys($container->getDefinitions()), ...array_keys($container->getAliases())],
            static fn (string $id): bool => !str_starts_with($id, 'service_container') && !str_starts_with($id, 'Psr\\Container') && !str_starts_with($id, 'Symfony\\Component\\DependencyInjection'),
        ));
    }
}
