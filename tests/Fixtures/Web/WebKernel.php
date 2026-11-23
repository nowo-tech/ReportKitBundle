<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\MemoryDirectory;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\RecordingMailer;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function in_array;
use function sys_get_temp_dir;

/**
 * Kernel of the report pages tests: Framework (forms, rate limiter), Twig, UiKit, FormKit, the bundle with
 * `web_ui.enabled` and, unless `$security` is false, SecurityBundle with HTTP basic users `alice`, `bob` (ROLE_USER),
 * `carol` (ROLE_GUEST only) and `root` (ROLE_USER, owner allowed context-free). Routes: the bundle's loader.
 */
final class WebKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_report_kit` configuration (merged over the web defaults)
     * @param (Closure(ContainerConfigurator): void)|null $services extra host services
     * @param bool $security register SecurityBundle
     * @param list<class-string> $skipBundles bundles to leave out (requirements tests)
     * @param string|null $databaseUrl S3: DoctrineBundle (ORM) on this URL, sessions and CSRF, subscriptions on with the
     *                                 {@see MemoryDirectory} and {@see RecordingMailer} services; null = no subscriptions
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?Closure $services = null,
        private readonly bool $security = true,
        private readonly array $skipBundles = [],
        private readonly ?string $databaseUrl = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        foreach ([FrameworkBundle::class, TwigBundle::class, TwigExtraBundle::class, NowoUiKitBundle::class, NowoFormKitBundle::class, NowoReportKitBundle::class] as $bundle) {
            if (!in_array($bundle, $this->skipBundles, true)) {
                yield new $bundle();
            }
        }
        if ($this->security) {
            yield new SecurityBundle();
        }
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
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
        return sys_get_temp_dir() . '/nowo-report-kit-web-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S2-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
            'default_locale'        => 'en',
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'form'                  => ['enabled' => true],
            'csrf_protection'       => $this->databaseUrl !== null,
            'rate_limiter'          => ['enabled' => true],
        ] + ($this->databaseUrl !== null ? ['session' => ['storage_factory_id' => 'session.storage.factory.mock_file']] : []));
        if ($this->databaseUrl !== null) {
            $container->extension('doctrine', ['dbal' => ['url' => $this->databaseUrl], 'orm' => []]);
            $container->services()->set(MemoryDirectory::class)->public()->call('add', ['alice'])->call('add', ['bob'])->call('add', ['newbie'])->call('add', ['root']);
            $container->services()->set(RecordingMailer::class)->public();
        }
        $container->extension('twig', ['strict_variables' => true]);
        if ($this->security) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'providers'        => ['users' => ['memory' => ['users' => [
                    'alice'  => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                    'bob'    => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                    'carol'  => ['password' => 'pw', 'roles' => ['ROLE_GUEST']],
                    'root'   => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                    'newbie' => ['password' => 'pw', 'roles' => ['ROLE_USER']],
                ]]]],
                'firewalls' => ['main' => ['stateless' => true, 'http_basic' => null, 'provider' => 'users']],
            ]);
        }
        $config = $this->bundleConfig;
        $config += ['enabled' => true];
        $config['owner']  = ($config['owner'] ?? []) + ['id_field' => 'userIdentifier'];
        $config['web_ui'] = ($config['web_ui'] ?? []) + ['enabled' => true];
        if ($this->databaseUrl !== null) {
            $config['subscriptions'] = ($config['subscriptions'] ?? []) + ['enabled' => true];
            $config['owner'] += ['directory' => MemoryDirectory::class];
            $config['mail'] = ($config['mail'] ?? []) + ['mailer' => RecordingMailer::class];
        }
        $container->extension('nowo_report_kit', $config);
        $container->services()->set('logger', NullLogger::class);
        if ($this->services instanceof Closure) {
            ($this->services)($container);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_report_kit_ui');
    }
}
