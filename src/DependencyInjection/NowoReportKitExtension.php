<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection;

use Closure;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactoryInterface;
use Nowo\ReportKitBundle\Application\Access\UnconfiguredReportAccessChecker;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Export\ReportExporter;
use Nowo\ReportKitBundle\Application\Owner\PropertyOwnerResolver;
use Nowo\ReportKitBundle\Application\ReportBuilder;
use Nowo\ReportKitBundle\Application\ReportParametersResolver;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Run\DueReportsDispatcher;
use Nowo\ReportKitBundle\Application\Run\RunRetention;
use Nowo\ReportKitBundle\Application\Run\ScheduledReportRunner;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Application\Subscription\Subscriptions;
use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\ReportParametersResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Infrastructure\Cache\PermissionCheckerWarmer;
use Nowo\ReportKitBundle\Infrastructure\Collation\ReportKitExactKeyColumns;
use Nowo\ReportKitBundle\Infrastructure\Console\DispatchDueCommand;
use Nowo\ReportKitBundle\Infrastructure\Console\PruneRunsCommand;
use Nowo\ReportKitBundle\Infrastructure\Csv\BuiltInCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Csv\ControllerKitCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener;
use Nowo\ReportKitBundle\Infrastructure\Filesystem\PrivateTempDirectory;
use Nowo\ReportKitBundle\Infrastructure\Http\ReportKitConflictListener;
use Nowo\ReportKitBundle\Infrastructure\Mail\DisabledReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Mail\SymfonyReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Messenger\DispatchDueReportsHandler;
use Nowo\ReportKitBundle\Infrastructure\Messenger\PruneReportRunsHandler;
use Nowo\ReportKitBundle\Infrastructure\Messenger\RunScheduledReportHandler;
use Nowo\ReportKitBundle\Infrastructure\Owner\DoctrineOwnerDirectory;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfDocumentRenderer;
use Nowo\ReportKitBundle\Infrastructure\Pdf\DompdfFontCacheWarmer;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ReportKitBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\ReportKitBundle\Infrastructure\Persistence\DbalSubscriptionStore;
use Nowo\ReportKitBundle\Infrastructure\Rendering\CsvReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\JsonReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\PdfReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Rendering\XlsxReportRenderer;
use Nowo\ReportKitBundle\Infrastructure\Scheduler\ReportKitScheduleProvider;
use Nowo\ReportKitBundle\Infrastructure\Security\ConfigurableReportKitAccessChecker;
use Nowo\ReportKitBundle\Infrastructure\Twig\TemplateCheckWarmer;
use Nowo\ReportKitBundle\Presentation\Form\ReportFilterType;
use Nowo\ReportKitBundle\Presentation\Form\ScheduleType;
use Nowo\ReportKitBundle\Presentation\Http\RenderedFileResponder;
use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Nowo\ReportKitBundle\Presentation\Http\ReportKitGuard;
use Nowo\ReportKitBundle\Presentation\Http\ReportRateLimiter;
use Nowo\ReportKitBundle\Presentation\Http\SubscriptionController;
use Nowo\ReportKitBundle\Presentation\Routing\ReportRouteLoader;
use Nowo\ReportKitBundle\Presentation\Routing\RouterReportLinks;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function class_exists;
use function in_array;
use function interface_exists;
use function is_array;
use function sprintf;

/**
 * Loads `nowo_report_kit`: validates the strict configuration tree, publishes it as container parameters and, when
 * `enabled` is true, registers the S1 services (model, registry, parameter resolver, renderers, PDF runner, brand
 * resolver, exporter, responder), the S2 builder (owner, scope and access SPIs) and, with `web_ui.enabled`, the report
 * pages. `enabled: false` (the default) registers nothing but the route loader, which then loads no route.
 */
final class NowoReportKitExtension extends Extension implements PrependExtensionInterface
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

    /** Doctrine mapping name of the bundle tables. */
    public const MAPPING_NAME = 'NowoReportKitBundle';

    /** Service id of the subscription store. */
    public const STORE = 'nowo_report_kit.subscription_store';

    /** MailKit's readiness port (optional bridge). */
    public const MAIL_KIT_READINESS = 'Nowo\\MailKitBundle\\Readiness\\OutboundMailReadinessInterface';

    /** Symfony Mailer (suggested). */
    public const SYMFONY_MAILER = 'Symfony\\Component\\Mailer\\MailerInterface';

    /** Symfony Scheduler (suggested). */
    public const SCHEDULER = 'Symfony\\Component\\Scheduler\\RecurringMessage';

    /** Cron expressions of the Scheduler recipe (suggested). */
    public const CRON_EXPRESSION = 'Cron\\CronExpression';

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
        $this->engineCheck = $installed === null ? static fn (string $class): bool => class_exists($class) || interface_exists($class) : $installed(...);
    }

    /**
     * The extension alias, `nowo_report_kit`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Standard hosts (`doctrine.auto_mapping: true`, the default): registers the XML mapping (or the attribute twins)
     * on `doctrine.entity_manager` while the subscriptions are on and the host configures the ORM.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(self::ALIAS));
        if ($config['enabled'] !== true || !$config['doctrine']['auto_mapping'] || !self::subscriptionsOn($config, true)) {
            return;
        }
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            $hasOrm = $hasOrm || (is_array($doctrine) && isset($doctrine['orm']));
        }
        if (!$hasOrm) {
            return;
        }
        $mapping = $config['doctrine']['mapping'] === 'attribute'
            ? ['type' => 'attribute', 'dir' => __DIR__ . '/../Infrastructure/Doctrine/Entity', 'prefix' => 'Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity']
            : ['type' => 'xml', 'dir' => __DIR__ . '/../Resources/config/doctrine', 'prefix' => 'Nowo\ReportKitBundle\Domain'];
        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$config['doctrine']['entity_manager'] => ['mappings' => [self::MAPPING_NAME => $mapping + ['is_bundle' => false]]]]],
        ]);
    }

    /**
     * Whether DoctrineBundle is registered (`load()` runs on a merge container that only knows the kernel's bundles).
     */
    private function hasDoctrine(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return $container->hasExtension('doctrine') || (is_array($bundles) && isset($bundles['DoctrineBundle']));
    }

    /**
     * Whether the subscriptions are on: `true`, or `auto` with DoctrineBundle and an owner directory (`owner.directory`
     * or `owner.class`).
     *
     * @param array<string, mixed> $config
     */
    public static function subscriptionsOn(array $config, bool $doctrine): bool
    {
        /** @var array{enabled: bool|string} $subscriptions */
        $subscriptions = $config['subscriptions'];
        /** @var array{class: string|null, directory: string|null} $owner */
        $owner = $config['owner'];
        if ($subscriptions['enabled'] === true) {
            return true;
        }

        return $subscriptions['enabled'] === ConfigNodes::AUTO && $doctrine && ($owner['directory'] !== null || $owner['class'] !== null);
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
        $doctrine      = $this->hasDoctrine($container);
        $subscriptions = $config['enabled'] === true && self::subscriptionsOn($config, $doctrine);
        if ($subscriptions && !$doctrine) {
            throw new InvalidConfigurationException('"nowo_report_kit.subscriptions.enabled" is true but DoctrineBundle is not registered: the subscriptions are stored through Doctrine DBAL (install doctrine/doctrine-bundle, or set it to "auto" / false).');
        }
        if ($subscriptions && $config['owner']['directory'] === null && $config['owner']['class'] === null) {
            throw new InvalidConfigurationException('"nowo_report_kit.subscriptions.enabled" is true but no owner directory is configured: set "nowo_report_kit.owner.directory" (an OwnerDirectoryInterface service) or "nowo_report_kit.owner.class" (the Doctrine user class), or set subscriptions.enabled to "auto" / false.');
        }
        $container->setParameter(self::ALIAS . '.subscriptions', $subscriptions);
        // Always registered, so a host (and the Flex recipe) can import the routes while the pages are off: no route then.
        $container->register('nowo_report_kit.web.route_loader', ReportRouteLoader::class)
            ->setArguments([$config['enabled'] === true && $config['web_ui']['enabled'] === true, $config['web_ui']['path_prefix'], 'nowo_report_kit.web.controller', $subscriptions ? 'nowo_report_kit.web.subscription_controller' : null])
            ->addTag('routing.loader');
        if ($config['enabled'] !== true) {
            return;
        }

        $container->setParameter(self::ALIAS . '.formats', $formats);
        $this->registerCore($container, $config, $formats);
        $this->registerRenderers($container, $config, $formats, $controllerKit !== false && $this->installed(self::CONTROLLER_KIT_CSV));
        $this->registerBuilder($container, $config);
        if ($subscriptions) {
            $this->registerSubscriptions($container, $config);
        }
        if ($config['web_ui']['enabled'] === true) {
            $this->registerWebUi($container, $config, $subscriptions);
        }
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
                ->setArguments([new Reference(BrandProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE), $config['brand']['context_type']]);
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
     * The S2 application services: owner resolver, scope resolver, access checker, builder.
     *
     * @param array<string, mixed> $config
     */
    private function registerBuilder(ContainerBuilder $container, array $config): void
    {
        if ($config['owner']['resolver'] !== null) {
            $container->setAlias('nowo_report_kit.owner_resolver', $config['owner']['resolver']);
        } else {
            $container->register('nowo_report_kit.owner_resolver', PropertyOwnerResolver::class)
                ->setArguments([$config['owner']['type'], $config['owner']['class'], $config['owner']['id_field']]);
        }
        $container->setAlias(OwnerResolverInterface::class, 'nowo_report_kit.owner_resolver')->setPublic(true);

        if ($config['scope']['resolver'] !== null) {
            $container->setAlias('nowo_report_kit.scope_resolver', $config['scope']['resolver']);
        } else {
            $container->register('nowo_report_kit.scope_resolver', NoContextScopeResolver::class);
        }
        $container->setAlias(ReportScopeResolverInterface::class, 'nowo_report_kit.scope_resolver')->setPublic(true);

        if ($config['scope']['access_checker'] !== null) {
            $container->setAlias('nowo_report_kit.access_checker', $config['scope']['access_checker']);
        } else {
            $container->register('nowo_report_kit.access_checker', UnconfiguredReportAccessChecker::class);
            // Fail closed and loud: a type with a permission and no checker stops cache:warmup (contract §4.3).
            $container->register('nowo_report_kit.permission_checker_warmer', PermissionCheckerWarmer::class)
                ->setArguments([new Reference('nowo_report_kit.type_registry')])
                ->addTag('kernel.cache_warmer');
        }
        $container->setAlias(ReportAccessCheckerInterface::class, 'nowo_report_kit.access_checker')->setPublic(true);

        $container->register('nowo_report_kit.builder', ReportBuilder::class)
            ->setArguments([
                new Reference('nowo_report_kit.type_registry'),
                new Reference('nowo_report_kit.parameters_resolver'),
                new Reference('nowo_report_kit.scope_resolver'),
                new Reference('nowo_report_kit.access_checker'),
                new Reference(ClockInterface::class),
                $config['schedule']['default_timezone'],
                new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        $container->setAlias(ReportBuilderInterface::class, 'nowo_report_kit.builder')->setPublic(true);
    }

    /**
     * The report pages (`web_ui.enabled`): area checker, guard, rate limits, filter form, controller, route loader.
     * {@see WebUiRequirementsPass} checks UiKit, FormKit, Twig and SecurityBundle once every bundle is loaded.
     *
     * @param array<string, mixed> $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config, bool $subscriptions): void
    {
        $web      = $config['web_ui'];
        $security = $web['security'];
        if ($security['access_checker'] !== null) {
            $container->setAlias('nowo_report_kit.web.access_checker', $security['access_checker']);
        } else {
            $container->register('nowo_report_kit.web.access_checker', ConfigurableReportKitAccessChecker::class)
                ->setArguments([new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE), $security['access_roles']]);
        }
        $container->setAlias(ReportKitAccessCheckerInterface::class, 'nowo_report_kit.web.access_checker')->setPublic(true);

        $container->register('nowo_report_kit.web.guard', ReportKitGuard::class)
            ->setArguments([
                new Reference('nowo_report_kit.web.access_checker'),
                new Reference('nowo_report_kit.owner_resolver'),
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $security['allow_unauthenticated'],
            ]);

        $limits  = $config['limits'];
        $storage = new Definition(CacheStorage::class, [new Reference($limits['cache_pool'])]);
        $lock    = new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        foreach (['views', 'exports'] as $name) {
            $container->register('nowo_report_kit.web.limiter.' . $name, RateLimiterFactory::class)
                ->setArguments([
                    ['id' => 'nowo_report_kit_' . $name, 'policy' => 'sliding_window', 'limit' => $limits[$name]['limit'], 'interval' => $limits[$name]['interval']],
                    $storage,
                    $lock,
                ]);
        }
        $container->register('nowo_report_kit.web.rate_limiter', ReportRateLimiter::class)
            ->setArguments([new Reference('nowo_report_kit.web.limiter.views'), new Reference('nowo_report_kit.web.limiter.exports')]);

        $container->register('nowo_report_kit.web.filter_type', ReportFilterType::class)->addTag('form.type');

        $templates = $web['templates'];
        $container->register('nowo_report_kit.web.controller', ReportController::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('nowo_report_kit.web.guard'),
                new Reference('nowo_report_kit.builder'),
                new Reference('nowo_report_kit.exporter'),
                new Reference('nowo_report_kit.responder'),
                new Reference('nowo_report_kit.web.rate_limiter'),
                new Reference('nowo_report_kit.brand_resolver'),
                new Reference('nowo_report_kit.render_limits'),
                new Reference(Environment::class),
                new Reference('form.factory'),
                new Reference('router'),
                new Reference(TranslatorInterface::class),
                [
                    'index'    => $templates['index'] ?? '@NowoReportKit/web/index.html.twig',
                    'show'     => $templates['show'] ?? '@NowoReportKit/web/show.html.twig',
                    'sections' => $templates['sections'] ?? '@NowoReportKit/web/_sections.html.twig',
                ],
                $web['layout_template'],
                new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $subscriptions ? new Reference(SubscriptionsInterface::class) : null,
                new Reference('nowo_report_kit.type_registry'),
            ])
            ->addTag('controller.service_arguments');

        if ($subscriptions) {
            $container->register('nowo_report_kit.web.subscription_controller', SubscriptionController::class)
                ->setPublic(true)
                ->setArguments([
                    new Reference('nowo_report_kit.web.guard'),
                    new Reference('nowo_report_kit.web.rate_limiter'),
                    new Reference('nowo_report_kit.builder'),
                    new Reference(SubscriptionsInterface::class),
                    new Reference('nowo_report_kit.type_registry'),
                    new Reference(OwnerDirectoryInterface::class),
                    new Reference('nowo_report_kit.settings_provider'),
                    new Reference('form.factory'),
                    new Reference(CsrfOnlyFormFactoryInterface::class),
                    new Reference(RemoteDialogResponder::class),
                    new Reference(Environment::class),
                    new Reference('router'),
                    new Reference(TranslatorInterface::class),
                    [
                        'page'     => '@NowoReportKit/web/form.html.twig',
                        'fragment' => $templates['schedule_dialog'] ?? '@NowoReportKit/web/_dialog_form.html.twig',
                    ],
                    $config['subscriptions']['frequencies'],
                    $config['subscriptions']['default_hour'],
                    $config['subscriptions']['allow_hour_choice'],
                    $config['schedule']['default_timezone'],
                    $web['layout_template'],
                ])
                ->addTag('controller.service_arguments');
            $container->register('nowo_report_kit.web.schedule_type', ScheduleType::class)->addTag('form.type');
        }

    }

    /**
     * S3: tables, store, subscriptions service, owner directory, mailer, runner, claim dispatcher, retention, Messenger
     * handlers, commands, the Scheduler recipe and the conflict answer.
     *
     * @param array<string, mixed> $config
     */
    private function registerSubscriptions(ContainerBuilder $container, array $config): void
    {
        /** @var array{entity_manager: string, table_prefix: string} $doctrine */
        $doctrine  = $config['doctrine'];
        $logger    = new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $clock     = new Reference(ClockInterface::class);
        $events    = new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $bus       = new Reference(MessageBusInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $messenger = interface_exists(MessageBusInterface::class);

        $container->register(AfterCommitQueue::class, AfterCommitQueue::class)
            ->setArguments([$logger])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $reset = $container->register(AfterCommitQueueResetListener::class, AfterCommitQueueResetListener::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'onFinishRequest', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -1024]);
        foreach (['Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'] as $event) {
            $reset->addTag('kernel.event_listener', ['event' => $event, 'method' => 'onBoundary', 'priority' => -1024]);
        }
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('doctrine.middleware');
        $container->register(ReportKitMetadataListener::class, ReportKitMetadataListener::class)
            ->setArguments([$doctrine['table_prefix'], $logger])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        $container->register(ReportKitExactKeyColumns::class, ReportKitExactKeyColumns::class)
            ->setArguments([$doctrine['table_prefix']])
            ->addTag('nowo_migrations_kit.exact_key_columns');

        $container->register(self::STORE, DbalSubscriptionStore::class)->setArguments([
            new Reference('doctrine'), new Reference(AfterCommitQueue::class), $doctrine['entity_manager'], $doctrine['table_prefix'], $logger,
            $config['concurrency']['attempts'], $config['concurrency']['lock_timeout_ms'], $config['conflict_response']['retry_after'],
        ]);
        $store = new Reference(self::STORE);
        $container->register('nowo_report_kit.conflict_policy', ConflictResponsePolicy::class)->setArguments([$config['conflict_response']['status'], $config['conflict_response']['retry_after']]);
        $container->register(ReportKitConflictListener::class, ReportKitConflictListener::class)
            ->setArguments([new Reference('nowo_report_kit.conflict_policy')])
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => ReportKitConflictListener::PRIORITY]);

        $container->register('nowo_report_kit.subscriptions', Subscriptions::class)->setArguments([
            $store, new Reference('nowo_report_kit.type_registry'), new Reference('nowo_report_kit.settings_provider'), $clock,
            $config['subscriptions']['max_per_owner'], $config['subscriptions']['frequencies'], $events, $logger,
        ]);
        $container->setAlias(SubscriptionsInterface::class, 'nowo_report_kit.subscriptions')->setPublic(true);

        /** @var array{type: string, class: class-string|null, id_field: string, directory: string|null} $owner */
        $owner = $config['owner'];
        if ($owner['directory'] !== null) {
            $container->setAlias('nowo_report_kit.owner_directory', $owner['directory']);
        } else {
            $container->register('nowo_report_kit.owner_directory', DoctrineOwnerDirectory::class)
                ->setArguments([new Reference('doctrine'), $owner['class'], $owner['id_field'], $owner['type']]);
        }
        $container->setAlias(OwnerDirectoryInterface::class, 'nowo_report_kit.owner_directory')->setPublic(true);

        $this->registerMailer($container, $config);

        $container->register('nowo_report_kit.links', RouterReportLinks::class)->setArguments([
            new Reference('router'),
            $config['web_ui']['enabled'] === true,
            $config['links']['base_url'],
            '%router.request_context.base_url%',
            '%router.request_context.host%',
            '%router.request_context.scheme%',
        ]);
        $container->register('nowo_report_kit.runner', ScheduledReportRunner::class)->setArguments([
            $store,
            new Reference('nowo_report_kit.type_registry'),
            new Reference('nowo_report_kit.builder'),
            new Reference('nowo_report_kit.exporter'),
            new Reference(OwnerDirectoryInterface::class),
            new Reference('nowo_report_kit.owner_resolver'),
            new Reference(ReportMailerInterface::class),
            new Reference('nowo_report_kit.links'),
            $clock,
            $config['schedule']['max_failures'],
            $config['schedule']['stale_claim_minutes'],
            $config['mail']['max_attachment_bytes'],
            new Reference('translation.locale_switcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $events,
            $logger,
        ]);
        $container->setAlias(ScheduledReportRunner::class, 'nowo_report_kit.runner')->setPublic(true);
        $container->register('nowo_report_kit.dispatcher', DueReportsDispatcher::class)->setArguments([
            $store, new Reference('nowo_report_kit.settings_provider'), $clock, $config['schedule']['batch'], $config['schedule']['stale_claim_minutes'], $config['messenger']['max_seconds'], $logger,
        ]);
        $container->setAlias(DueReportsDispatcher::class, 'nowo_report_kit.dispatcher')->setPublic(true);
        $container->register('nowo_report_kit.retention', RunRetention::class)->setArguments([
            $store, $clock, $config['retention']['run_days'], $config['retention']['batch_size'], $config['messenger']['max_seconds'],
        ]);
        $container->setAlias(RunRetention::class, 'nowo_report_kit.retention')->setPublic(true);

        // Registered from their #[AsCommand] / #[AsMessageHandler] attributes (REQ-SF-003): autoconfiguration tags them.
        $container->register(DispatchDueCommand::class, DispatchDueCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference('nowo_report_kit.dispatcher'), new Reference('nowo_report_kit.runner'), $messenger ? $bus : null]);
        $container->register(PruneRunsCommand::class, PruneRunsCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference('nowo_report_kit.retention')]);
        if ($messenger) {
            $container->register(DispatchDueReportsHandler::class, DispatchDueReportsHandler::class)
                ->setAutoconfigured(true)
                ->setArguments([new Reference('nowo_report_kit.dispatcher'), new Reference('nowo_report_kit.runner'), $bus]);
            $container->register(RunScheduledReportHandler::class, RunScheduledReportHandler::class)
                ->setAutoconfigured(true)
                ->setArguments([new Reference('nowo_report_kit.runner')]);
            $container->register(PruneReportRunsHandler::class, PruneReportRunsHandler::class)
                ->setAutoconfigured(true)
                ->setArguments([new Reference('nowo_report_kit.retention'), $bus, $logger]);
        }
        if ($config['schedule']['enabled'] === true && $this->installed(self::SCHEDULER)) {
            if (!$this->installed(self::CRON_EXPRESSION)) {
                throw new InvalidConfigurationException('"nowo_report_kit.schedule.enabled" registers a Symfony Scheduler schedule, which needs dragonmantank/cron-expression: run "composer require dragonmantank/cron-expression" (or run nowo:report-kit:dispatch-due from your own scheduler).');
            }
            $container->register(ReportKitScheduleProvider::class, ReportKitScheduleProvider::class)
                ->setArguments([$config['schedule']['cron']])
                ->addTag('scheduler.schedule_provider', ['name' => ReportKitScheduleProvider::NAME]);
        }
    }

    /**
     * `mail.mailer`, else Symfony Mailer (`mail.enabled`: true needs symfony/mailer; auto follows the install), else a
     * mailer that is never ready (every run skips with `mail_not_ready`).
     *
     * @param array<string, mixed> $config
     */
    private function registerMailer(ContainerBuilder $container, array $config): void
    {
        /** @var array{enabled: bool|string, template: string, mailer: string|null, from: string|null} $mail */
        $mail = $config['mail'];
        if ($mail['mailer'] !== null) {
            $container->setAlias(ReportMailerInterface::class, $mail['mailer'])->setPublic(true);

            return;
        }
        $installed = $this->installed(self::SYMFONY_MAILER);
        if ($mail['enabled'] === true && !$installed) {
            throw new InvalidConfigurationException('"nowo_report_kit.mail.enabled" is true but symfony/mailer is not installed: run "composer require symfony/mailer", set "mail.mailer", or set it to "auto".');
        }
        if ($mail['enabled'] === false || !$installed) {
            $container->register('nowo_report_kit.mailer', DisabledReportMailer::class);
        } else {
            $mailKit = $config['bridges']['mail_kit'] !== false && interface_exists(self::MAIL_KIT_READINESS);
            $container->register('nowo_report_kit.mailer', SymfonyReportMailer::class)->setArguments([
                new Reference('mailer.mailer'),
                new Reference(Environment::class),
                new Reference(TranslatorInterface::class),
                new Reference('nowo_report_kit.brand_resolver'),
                $mail['template'],
                $mail['from'],
                $mailKit ? new Reference(self::MAIL_KIT_READINESS, ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
            ]);
            $container->register('nowo_report_kit.mail_template_check_warmer', TemplateCheckWarmer::class)
                ->setArguments([new Reference(Environment::class), ['mail.template' => $mail['template']]])
                ->addTag('kernel.cache_warmer');
        }
        $container->setAlias(ReportMailerInterface::class, 'nowo_report_kit.mailer')->setPublic(true);
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
                ->setArguments([new Reference(Environment::class), $temp, $limits, $fontCache, new Reference(ClockInterface::class)]);
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
