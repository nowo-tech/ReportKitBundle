<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Nowo\ReportKitBundle\Tests\Fixtures\EchoController;
use Nowo\ReportKitBundle\Tests\Fixtures\ExportController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function sys_get_temp_dir;

/**
 * Standard-host kernel of the integration tests: FrameworkBundle, the bundle and, on demand, DoctrineBundle (DBAL
 * only). Routes: `/echo` answers the request's `X-Test-Owner` header and the bundle's API prefix parameter; with the bundle
 * enabled, `/export/{type}/{owner}.{format}` builds and exports a report ({@see ExportController}).
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_report_kit` configuration
     * @param string|null $databaseUrl DBAL URL; null = no DoctrineBundle
     * @param (Closure(ContainerConfigurator): void)|null $services extra host services (DDD fixtures)
     * @param bool $twig register TwigBundle
     * @param bool $translator enable the framework translator
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
        private readonly ?Closure $services = null,
        private readonly bool $twig = true,
        private readonly bool $translator = true,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->twig) {
            yield new TwigBundle();
            yield new TwigExtraBundle();
        }
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        yield new NowoReportKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-report-kit-tests';
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->translator) {
            return;
        }
        // Symfony 8.2 always registers an identity translator when the translation component is installed, even with
        // `framework.translator` off: drop it to stand for a host without any translator.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['translator', TranslatorInterface::class, 'translator.default', 'translator.identity'] as $id) {
                    if ($container->hasAlias($id)) {
                        $container->removeAlias($id);
                    }
                    if ($container->hasDefinition($id)) {
                        $container->removeDefinition($id);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S0-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
            'default_locale'        => 'en',
            'translator'            => ['enabled' => $this->translator, 'fallbacks' => ['en']],
            // No forms or validation here (the pages have their own kernel): they would register the identity translator.
            'form'       => ['enabled' => false],
            'validation' => ['enabled' => false],
        ]);
        if ($this->twig) {
            $container->extension('twig', ['strict_variables' => true]);
        }
        if ($this->databaseUrl !== null) {
            $container->extension('doctrine', ['dbal' => ['url' => $this->databaseUrl]]);
        }
        $container->extension('nowo_report_kit', $this->bundleConfig);
        $container->services()->set('logger', NullLogger::class);
        $container->services()
            ->set(EchoController::class)
            ->arg('$apiPrefix', '%nowo_report_kit.api_prefix%')
            ->public()
            ->tag('controller.service_arguments');
        if ($this->bundleConfig['enabled'] ?? false) {
            $container->services()
                ->set(ExportController::class)
                ->autowire()
                ->public()
                ->tag('controller.service_arguments');
        }
        if ($this->services instanceof Closure) {
            ($this->services)($container);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('echo', '/echo')->controller(EchoController::class)->methods(['GET']);
        if ($this->bundleConfig['enabled'] ?? false) {
            $routes->add('export', '/export/{type}/{owner}.{format}')->controller(ExportController::class)->methods(['GET']);
        }
    }
}
