<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use LogicException;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\LedgerAccess;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\PlainReportType;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * REQ-ARCH-001, DDD host: the same pages with the port implemented directly (tagged by hand, no base class), a host
 * owner resolver (`member:<id>`), report access checker and area checker, and a custom path prefix. Plus the
 * demo-only `allow_unauthenticated` path without SecurityBundle.
 */
final class DddHostPagesTest extends WebKernelTestCase
{
    public function testHostSpisDecideEverything(): void
    {
        $kernel = $this->bootWeb([
            'owner'  => ['resolver' => 'app.access'],
            'scope'  => ['access_checker' => 'app.access'],
            'web_ui' => ['path_prefix' => '/ops/reports', 'security' => ['access_checker' => 'app.access']],
        ], static function (ContainerConfigurator $c): void {
            $c->services()->set(PlainReportType::class)->tag(ReportTypeInterface::TAG);
            $c->services()->set('app.access', LedgerAccess::class);
        });

        [$status, , $html] = $this->get($kernel, '/ops/reports', 'bob');
        self::assertSame(200, $status);
        self::assertStringContainsString('href="/ops/reports/ledger"', $html);
        [$status, , $html] = $this->get($kernel, '/ops/reports/ledger', 'bob');
        self::assertSame(200, $status);
        self::assertStringContainsString('<td class="nowo-ui-table__cell nowo-ui-cell--start" data-label="Owner">bob</td>', $html);

        [, , $html] = $this->get($kernel, '/ops/reports', 'alice');
        self::assertStringNotContainsString('/ops/reports/ledger', $html, 'not granted: not listed');
        self::assertSame(403, $this->get($kernel, '/ops/reports/ledger/export.csv', 'alice')[0]);
        self::assertSame(403, $this->get($kernel, '/ops/reports', 'carol')[0], 'host area checker');
        self::assertSame(404, $this->get($kernel, '/reports', 'bob')[0]);
    }

    public function testDemoGuestWithoutSecurityBundle(): void
    {
        $kernel = $this->bootTeam(['web_ui' => ['security' => ['allow_unauthenticated' => true]]], security: false);

        [$status, , $html] = $this->get($kernel, '/reports/personal');
        self::assertSame(200, $status);
        self::assertStringContainsString('guest:anonymous', $html);
    }

    public function testPagesRefuseToBuildWithoutSecurityBundle(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs SecurityBundle');
        $this->bootTeam(security: false);
    }
}
