<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Presentation;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PauseReason;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\RunStatus;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Nowo\ReportKitBundle\Presentation\Http\SubscriptionRows;
use Nowo\ReportKitBundle\Presentation\Routing\RouterReportLinks;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\Translator;

/**
 * Mail links never come from a request (REQ-AUTH-011); list rows of the subscriptions table.
 */
final class LinksAndRowsTest extends TestCase
{
    public function testLinksUseTheConfiguredBaseOrTheDefaultUri(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $routes = new RouteCollection();
        $routes->add(ReportController::ROUTE_INDEX, new Route('/reports'));
        $routes->add(ReportController::ROUTE_SHOW, new Route('/reports/{type}'));
        $router->method('getRouteCollection')->willReturn($routes);

        $base = new RouterReportLinks($router, true, 'https://app.example.test/sub');
        self::assertSame('https://app.example.test/sub/reports/orders?period=7&context=project:1', $base->reportUrl('orders', ['period' => 7, 'context' => 'project:1']));
        self::assertSame('https://app.example.test/sub/reports', $base->subscriptionsUrl());

        self::assertSame('https://default.example.test/reports', (new RouterReportLinks($router, true, null, 'https://default.example.test/'))->subscriptionsUrl());
        self::assertSame('http://localhost/app/reports', (new RouterReportLinks($router, true, null, '/app', 'localhost', 'http'))->subscriptionsUrl());

        $off = new RouterReportLinks($router, false, 'https://app.example.test');
        self::assertNull($off->reportUrl('orders', []));
        self::assertNull($off->subscriptionsUrl());
    }

    public function testRowsDescribeTheScheduleAndTheStatus(): void
    {
        $translator = new Translator('en');
        $daily      = $this->view(new Schedule(Frequency::Daily, hour: 7, timezone: 'Europe/Madrid'), true, null, RunStatus::Sent, new ContextRef('project', '42'));
        $row        = SubscriptionRows::row($daily, null, $translator);
        self::assertSame(['orders', 'subscriptions.parameters · 42', 'subscriptions.schedule.daily (Europe/Madrid)', '2026-11-24 07:00', 'subscriptions.status.enabled · subscriptions.last.sent', true], [$row['report'], $row['parameters'], $row['schedule'], $row['next_run'], $row['status'], $row['enabled']]);

        $weekly = SubscriptionRows::row($this->view(new Schedule(Frequency::Weekly, 3), false, PauseReason::NoAccess, null), new PersonalReportType($translator), $translator);
        self::assertSame(['report_type.personal.title', 'subscriptions.schedule.weekly (UTC)', '—', 'subscriptions.status.paused.no_access'], [$weekly['report'], $weekly['schedule'], $weekly['next_run'], $weekly['status']]);
        $monthly = SubscriptionRows::row($this->view(new Schedule(Frequency::Monthly, monthDay: 5), false, null, null), null, $translator);
        self::assertSame(['subscriptions.schedule.monthly (UTC)', 'subscriptions.status.paused.owner'], [$monthly['schedule'], $monthly['status']]);
    }

    private function view(Schedule $schedule, bool $enabled, ?PauseReason $reason, ?RunStatus $last, ?ContextRef $context = null): SubscriptionView
    {
        return new SubscriptionView(new SubscriptionId('01933b1c-5d2e-7a3b-8c4d-00000000a001'), new OwnerRef('user', 'alice'), 'orders', new ReportParameters(7, $context), $schedule, [], $enabled, $reason, new DateTimeImmutable('2026-11-24T06:00:00Z'), null, $last);
    }
}
