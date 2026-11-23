<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use Closure;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\RevenueReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\WebKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function count;
use function ob_get_clean;
use function ob_start;
use function preg_replace;

/**
 * Boots a {@see WebKernel} per call and sends requests as a user (HTTP basic) or anonymously.
 */
abstract class WebKernelTestCase extends TestCase
{
    /** @var list<WebKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(WebKernel::baseDir());
    }

    /**
     * The standard host of the tests: the three fixture types, the team scope resolver and access checker.
     *
     * @param array<string, mixed> $config
     */
    protected function bootTeam(array $config = [], bool $security = true, ?string $databaseUrl = null): WebKernel
    {
        return $this->bootWeb($config + ['scope' => ['resolver' => TeamScopeResolver::class, 'access_checker' => TeamAccessChecker::class]], static function (ContainerConfigurator $c): void {
            $s = $c->services();
            foreach ([OrdersReportType::class, RevenueReportType::class, PersonalReportType::class] as $type) {
                $s->set($type)->autowire()->autoconfigure();
            }
            $s->set(TeamScopeResolver::class);
            $s->set(TeamAccessChecker::class);
        }, $security, [], $databaseUrl);
    }

    /**
     * @param array<string, mixed> $config
     * @param (Closure(ContainerConfigurator): void)|null $services
     * @param list<class-string> $skipBundles
     */
    protected function bootWeb(array $config = [], ?Closure $services = null, bool $security = true, array $skipBundles = [], ?string $databaseUrl = null): WebKernel
    {
        $id              = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel          = new WebKernel($id, $config, $services, $security, $skipBundles, $databaseUrl);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }

    protected function doctrine(WebKernel $kernel): ManagerRegistry
    {
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    protected function get(WebKernel $kernel, string $uri, ?string $user = null): array
    {
        $server = $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw'];
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $response = $http->handle(Request::create($uri, 'GET', [], [], [], $server), HttpKernelInterface::MAIN_REQUEST, true);
        ob_start();
        $response->sendContent();
        $body    = (string) ob_get_clean();
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return [$response->getStatusCode(), $headers, $body];
    }
}
