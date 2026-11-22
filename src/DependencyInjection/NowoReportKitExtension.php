<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection;

use Closure;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportExporter;
use Nowo\ReportKitBundle\Application\ReportParametersResolver;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportParametersResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Infrastructure\Csv\BuiltInCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Csv\ControllerKitCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfDocumentRenderer;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfFontCacheWarmer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\CsvReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\JsonReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\PdfReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\XlsxReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Twig\TemplateCheckWarmer;
use Nowo\ReportKitBundle\Presentation\Http\RenderedFileResponder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function class_exists;
use function in_array;
use function sprintf;

/**
 * Loads `nowo_report_kit`: validates the strict configuration tree, publishes it as container parameters and, when
 * `enabled` is true, registers the S1 services (model, registry, parameter resolver, renderers, PDF runner, brand
 * resolver, exporter, responder). `enabled: false` (the default) registers nothing.
 */
final class NowoReportKitExtension extends Extension
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_report_kit';

    /** Service tag of the format renderers (internal). */
    public const RENDERER_TAG = 'nowo_report_kit.renderer';

    /**
     * Suggested format engines (Q-O3): `true` without the engine refuses to build; `auto` follows the install.
     *
     * @var array<string, array{string, string}>
     */
    public const FORMAT_ENGINES = [
        'xlsx' => ['OpenSpout\\Writer\\XLSX\\Writer', 'openspout/openspout'],
        'pdf'  => ['Dompdf\\Dompdf', 'dompdf/dompdf'],
    ];

    /** ControllerKit's CSV guard (Q-O4). */
    public const CONTROLLER_KIT_CSV = 'Nowo\\ControllerKitBundle\\Http\\CsvSafeWriter';

    /** @var Closure(string): bool */
    private readonly Closure $engineCheck;

    /**
     * @param (callable(string): bool)|null $installed whether a suggested package's class is installed; null =
     *                                                 `class_exists()` (tests pass their own to cover the "missing" paths)
     */
    public function __construct(?callable $installed = null)
    {
        $this->engineCheck = $installed === null ? static fn (string $class): bool => class_exists($class) : $installed(...);
    }

    /**
     * The extension alias, `nowo_report_kit`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Processes the configuration, sets the `nowo_report_kit.*` container parameters and registers the services.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config  = $this->processConfiguration(new Configuration(), $configs);
        $formats = [];
        foreach (['csv', 'xlsx', 'pdf', 'json'] as $format) {
            $enabled = $config['formats'][$format]['enabled'];
            $engine  = self::FORMAT_ENGINES[$format] ?? null;
            if ($engine !== null) {
                // The engines are suggested packages: true without them refuses to build, auto follows the install.
                $installed = $this->installed($engine[0]);
                if ($enabled === true && !$installed) {
                    throw new InvalidConfigurationException(sprintf('"nowo_report_kit.formats.%s.enabled" is true but %s is not installed: run "composer require %s" or set it to "auto" (Q-O3).', $format, $engine[0], $engine[1]));
                }
                $enabled = $enabled !== false && $installed;
            }
            if ($enabled === true) {
                $formats[] = $format;
            }
        }
        $controllerKit = $config['bridges']['controller_kit'];
        if ($controllerKit === true && !$this->installed(self::CONTROLLER_KIT_CSV)) {
            throw new InvalidConfigurationException('"nowo_report_kit.bridges.controller_kit" is true but nowo-tech/controller-kit-bundle is not installed (Q-O4).');
        }
        $container->setParameter(self::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(self::ALIAS . '.api_prefix', $config['api']['prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.config', $config);
        if ($config['enabled'] !== true) {
            return;
        }

        $container->setParameter(self::ALIAS . '.formats', $formats);
        $this->registerCore($container, $config, $formats);
        $this->registerRenderers($container, $config, $formats, $controllerKit !== false && $this->installed(self::CONTROLLER_KIT_CSV));
    }

    private function installed(string $class): bool
    {
        return ($this->engineCheck)($class);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $formats
     */
    private function registerCore(ContainerBuilder $container, array $config, array $formats): void
    {
        $container->registerForAutoconfiguration(ReportTypeInterface::class)->addTag(ReportTypeInterface::TAG);

        $container->register('nowo_report_kit.type_registry', ReportTypeRegistry::class)
            ->setArguments([new TaggedIteratorArgument(ReportTypeInterface::TAG), $config['types']['order'], $config['types']['disabled']]);
        $container->setAlias(ReportTypeRegistryInterface::class, 'nowo_report_kit.type_registry')->setPublic(true);

        $container->register('nowo_report_kit.parameters_resolver', ReportParametersResolver::class);
        $container->setAlias(ReportParametersResolverInterface::class, 'nowo_report_kit.parameters_resolver')->setPublic(true);

        if ($config['settings_provider'] !== null) {
            $container->setAlias('nowo_report_kit.settings_provider', $config['settings_provider']);
        } else {
            $container->register('nowo_report_kit.settings_provider', ConfigReportSettingsProvider::class)
                ->setArguments([true, $formats, $config['schedule']['enabled']]);
        }
        $container->setAlias(ReportSettingsProviderInterface::class, 'nowo_report_kit.settings_provider')->setPublic(true);

        if ($config['brand']['resolver'] !== null) {
            $container->setAlias('nowo_report_kit.brand_resolver', $config['brand']['resolver']);
        } else {
            $container->register('nowo_report_kit.brand_resolver', DefaultReportBrandResolver::class)
                ->setArguments([new Reference(BrandProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        }
        $container->setAlias(ReportBrandResolverInterface::class, 'nowo_report_kit.brand_resolver')->setPublic(true);

        $limits = $config['limits'];
        $container->register('nowo_report_kit.render_limits', RenderLimits::class)
            ->setArguments([$limits['max_rows_per_section'], $limits['pdf_max_rows'], $limits['pdf_max_cell'], $limits['render_seconds'], $limits['max_pdf_html_bytes']]);
        $container->register('nowo_report_kit.temp_directory', PrivateTempDirectory::class)->setArguments([$config['temp_dir']]);

        $container->register('nowo_report_kit.exporter', ReportExporter::class)
            ->setArguments([
                new TaggedIteratorArgument(self::RENDERER_TAG),
                new Reference('nowo_report_kit.settings_provider'),
                new Reference(ClockInterface::class),
                new Reference('nowo_report_kit.render_limits'),
                $config['filename_prefix'],
            ]);
        $container->setAlias(ReportExporterInterface::class, 'nowo_report_kit.exporter')->setPublic(true);

        $container->register('nowo_report_kit.responder', RenderedFileResponder::class);
        $container->setAlias(RenderedFileResponder::class, 'nowo_report_kit.responder')->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $formats
     */
    private function registerRenderers(ContainerBuilder $container, array $config, array $formats, bool $controllerKit): void
    {
        $limits     = new Reference('nowo_report_kit.render_limits');
        $temp       = new Reference('nowo_report_kit.temp_directory');
        $translator = new Reference(TranslatorInterface::class);

        if (in_array('csv', $formats, true)) {
            $container->register('nowo_report_kit.csv_guard', $controllerKit ? ControllerKitCsvCellGuard::class : BuiltInCsvCellGuard::class);
            $container->register('nowo_report_kit.renderer.csv', CsvReportRenderer::class)
                ->setArguments([new Reference('nowo_report_kit.csv_guard'), $translator, $limits])
                ->addTag(self::RENDERER_TAG);
        }
        if (in_array('xlsx', $formats, true)) {
            $container->register('nowo_report_kit.renderer.xlsx', XlsxReportRenderer::class)
                ->setArguments([$translator, $limits, $temp])
                ->addTag(self::RENDERER_TAG);
        }
        if (in_array('json', $formats, true)) {
            $container->register('nowo_report_kit.renderer.json', JsonReportRenderer::class)
                ->setArguments([$limits])
                ->addTag(self::RENDERER_TAG);
        }
        // Q-O10: the closed PDF runner is public API whenever Dompdf is installed, even with formats.pdf off.
        if ($this->installed(self::FORMAT_ENGINES['pdf'][0])) {
            $fontCache = '%kernel.cache_dir%/nowo_report_kit/fonts';
            $container->register('nowo_report_kit.pdf_document_renderer', DompdfDocumentRenderer::class)
                ->setArguments([new Reference(Environment::class), $temp, $limits, $fontCache]);
            $container->setAlias(PdfDocumentRendererInterface::class, 'nowo_report_kit.pdf_document_renderer')->setPublic(true);
            $container->register('nowo_report_kit.font_cache_warmer', DompdfFontCacheWarmer::class)
                ->setArguments([$temp, $fontCache])
                ->addTag('kernel.cache_warmer');
        }
        if (in_array('pdf', $formats, true)) {
            $container->register('nowo_report_kit.renderer.pdf', PdfReportRenderer::class)
                ->setArguments([
                    new Reference('nowo_report_kit.pdf_document_renderer'),
                    new Reference('nowo_report_kit.brand_resolver'),
                    $translator,
                    $limits,
                    $config['formats']['pdf']['template'],
                    $config['formats']['pdf']['paper'],
                    new Reference(BrandAssetReaderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->addTag(self::RENDERER_TAG);
            $container->register('nowo_report_kit.template_check_warmer', TemplateCheckWarmer::class)
                ->setArguments([new Reference(Environment::class), ['formats.pdf.template' => $config['formats']['pdf']['template']]])
                ->addTag('kernel.cache_warmer');
        }
    }
}
