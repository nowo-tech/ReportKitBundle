<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\HugeScopeResolver;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\WebKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function ob_get_clean;
use function ob_start;

/**
 * Subscription pages on unusual hosts: the demo guest cannot own subscriptions, a scope over the ceiling answers 503,
 * a context the person cannot read is refused before and after the POST.
 */
final class SubscriptionPagesEdgeTest extends WebKernelTestCase
{
    public function testTheDemoGuestCannotSchedule(): void
    {
        $kernel   = $this->bootTeam(['web_ui' => ['security' => ['allow_unauthenticated' => true]]], false, $this->database());
        [$status] = $this->request($kernel, '/reports/personal/schedule');
        self::assertSame(403, $status);
        [$status, $html] = $this->request($kernel, '/reports/personal');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('report-kit-schedule-open', $html);
        [, $list] = $this->request($kernel, '/reports');
        self::assertStringNotContainsString('report-kit-subscriptions-section', $list);
    }

    public function testAScopeOverTheCeilingAnswers503(): void
    {
        $kernel = $this->bootWeb(['scope' => ['resolver' => HugeScopeResolver::class]], static function (ContainerConfigurator $c): void {
            $c->services()->set(HugeScopeResolver::class);
            $c->services()->set(OrdersReportType::class)->autowire()->autoconfigure();
        }, true, [], $this->database());
        self::assertSame(503, $this->request($kernel, '/reports/orders/schedule', 'alice')[0]);
    }

    public function testAContextThePersonCannotReadIsAFormError(): void
    {
        $kernel = $this->bootTeam([], true, $this->database());
        $this->schema($kernel);
        [$status, $html] = $this->request($kernel, '/reports/orders/schedule?period=30&context=project:b1', 'alice');
        self::assertSame(200, $status);
        self::assertStringContainsString('data-testid="report-kit-form-errors"', $html);
        self::assertStringContainsString('context=project:b1', html_entity_decode($html), 'the POST keeps the asked context');
    }

    private function database(): string
    {
        (new Filesystem())->mkdir(WebKernel::baseDir());

        return 'sqlite:///' . WebKernel::baseDir() . '/edge.sqlite';
    }

    private function schema(WebKernel $kernel): void
    {
        $em = $this->doctrine($kernel)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @return array{int, string}
     */
    private function request(WebKernel $kernel, string $uri, ?string $user = null): array
    {
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $response = $http->handle(Request::create($uri, 'GET', [], [], [], $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']), HttpKernelInterface::MAIN_REQUEST, true);
        ob_start();
        $response->sendContent();

        return [$response->getStatusCode(), (string) ob_get_clean()];
    }
}
