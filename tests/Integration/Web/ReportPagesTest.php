<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use Nowo\ReportKitBundle\Contract\Event\ReportAccessCheckEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportExportedEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The report pages on a standard host (contract §6.1, §7.1, §14 "Access"): list, page, exports, 401 / 403 / 404,
 * scope narrowing (a context never offered by the scope stays out even when the checker names it), the context
 * fallback, rate limits and the exported event.
 */
final class ReportPagesTest extends WebKernelTestCase
{
    public function testListShowsOnlyTheTypesTheViewerMayOpen(): void
    {
        $kernel = $this->bootTeam();

        [$status, , $html] = $this->get($kernel, '/reports', 'alice');
        self::assertSame(200, $status);
        self::assertStringContainsString('data-testid="report-kit-index"', $html);
        foreach (['/reports/orders', '/reports/revenue', '/reports/personal'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $html);
        }

        // root has no context at all: revenue needs one (403 path) and orders runs without context.
        [, , $html] = $this->get($kernel, '/reports', 'root');
        self::assertStringNotContainsString('/reports/revenue', $html);
        self::assertStringContainsString('/reports/orders', $html);
    }

    public function testANewUserWithoutContextsSeesNoContextTypeZyM1(): void
    {
        $kernel = $this->bootTeam();

        [$status, $headers, $html] = $this->get($kernel, '/reports', 'newbie');
        self::assertSame(200, $status);
        self::assertSame('no-store, private', $headers['cache-control']);
        self::assertStringContainsString('/reports/personal', $html);
        self::assertStringNotContainsString('/reports/orders', $html, 'orders has no permission but takes contexts');
        self::assertStringNotContainsString('/reports/revenue', $html);
        foreach (['/reports/orders', '/reports/orders/export.csv', '/reports/orders/export.xlsx', '/reports/orders/export.pdf', '/reports/revenue', '/reports/revenue/export.csv'] as $uri) {
            [$status, , $body] = $this->get($kernel, $uri, 'newbie');
            self::assertSame(403, $status, $uri);
            self::assertStringNotContainsString('project:', $body, $uri);
        }
        self::assertSame(200, $this->get($kernel, '/reports/personal', 'newbie')[0]);
        // root has no context either, but the host's checker grants it context-free: the type sees the flag.
        [$status, , $html] = $this->get($kernel, '/reports/orders', 'root');
        self::assertSame(200, $status);
        self::assertStringContainsString('context-free-grant', $html);
        self::assertStringNotContainsString('project:', $html);
    }

    public function testAGrantOpensAZeroContextTypeExplicitlyZyM1(): void
    {
        $kernel     = $this->bootTeam();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ReportKitEvents::REPORT_ACCESS_CHECK, static function (ReportAccessCheckEvent $event): void {
            if ($event->owner->id === 'newbie' && $event->type === 'orders') {
                $event->grant();
            }
        });

        [, , $html] = $this->get($kernel, '/reports', 'newbie');
        self::assertStringContainsString('/reports/orders', $html);
        self::assertStringNotContainsString('/reports/revenue', $html, 'context required: still refused');
        [$status, , $html] = $this->get($kernel, '/reports/orders', 'newbie');
        self::assertSame(200, $status);
        self::assertStringContainsString('context-free-grant', $html);
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            self::assertSame(200, $this->get($kernel, '/reports/orders/export.' . $format, 'newbie')[0], $format);
        }
        [, , $csv] = $this->get($kernel, '/reports/orders/export.csv', 'newbie');
        self::assertStringContainsString('context-free-grant', $csv);
        self::assertStringNotContainsString('project:', $csv);
    }

    public function testAnonymousAndWrongRoleAreRefused(): void
    {
        $kernel = $this->bootTeam();

        [$status] = $this->get($kernel, '/reports');
        self::assertSame(401, $status);
        [$status] = $this->get($kernel, '/reports/orders/export.csv');
        self::assertSame(401, $status);
        [$status] = $this->get($kernel, '/reports', 'carol');
        self::assertSame(403, $status);
    }

    public function testReportPageRendersSectionsFiltersAndExports(): void
    {
        $kernel = $this->bootTeam();

        [$status, $headers, $html] = $this->get($kernel, '/reports/revenue?period=30&channel=shop&limit=5&gross=0&since=2026-11-01', 'alice');
        self::assertSame(200, $status, $html);
        self::assertSame('text/html; charset=UTF-8', $headers['content-type']);
        self::assertSame('no-store, private', $headers['cache-control']);
        self::assertStringContainsString('data-testid="report-kit-show"', $html);
        // Context required: the first granting context opens; a2 is in alice's scope but not granted, zz never offered.
        self::assertStringContainsString('project:a1', $html);
        self::assertStringNotContainsString('project:a2', $html);
        self::assertStringNotContainsString('project:zz', $html);
        self::assertStringContainsString('href="/reports/revenue/export.csv?period=30&amp;context=project:a1&amp;channel=shop&amp;limit=5&amp;gross=0&amp;since=2026-11-01"', $html);
        self::assertStringContainsString('data-testid="report-kit-export-xlsx"', $html);
        self::assertStringContainsString('data-testid="report-kit-export-pdf"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('data-nowo-ui-theme-only="dark"', $html);
        self::assertStringContainsString('data-testid="report-kit-section-empty"', $html);
        self::assertStringContainsString('name="period"', $html);
        self::assertStringContainsString('name="channel"', $html);
        self::assertStringContainsString('<option value="project:a1" selected="selected">Alpha</option>', $html);
        self::assertStringNotContainsString('Apollo', $html);
    }

    public function testUnknownTypeIs404AndRefusedTypeIs403(): void
    {
        $kernel = $this->bootTeam();

        [$status] = $this->get($kernel, '/reports/nope', 'alice');
        self::assertSame(404, $status);
        [$status] = $this->get($kernel, '/reports/nope/export.csv', 'alice');
        self::assertSame(404, $status);
        // root has no context: revenue (context required, permission) is refused.
        [$status] = $this->get($kernel, '/reports/revenue', 'root');
        self::assertSame(403, $status);
        [$status] = $this->get($kernel, '/reports/revenue/export.pdf', 'root');
        self::assertSame(403, $status);
        [$status] = $this->get($kernel, '/reports/orders/export.json', 'alice');
        self::assertSame(404, $status, 'JSON is the API\'s');
    }

    public function testExportsAreFilesOfTheViewersScopeOnly(): void
    {
        $kernel     = $this->bootTeam();
        $events     = [];
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ReportKitEvents::REPORT_EXPORTED, static function (ReportExportedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        [$status, $headers, $csv] = $this->get($kernel, '/reports/revenue/export.csv?context=project:a2', 'alice');
        self::assertSame(200, $status);
        self::assertSame('no-store, private', $headers['cache-control']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
        self::assertStringStartsWith('attachment; filename=report-revenue-alpha-', $headers['content-disposition']);
        self::assertStringContainsString("'=HYPERLINK", $csv, 'formula cells are neutralised');
        self::assertStringContainsString('project:a1,effective', $csv);
        self::assertStringNotContainsString('a2', $csv);

        foreach (['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'] as $format => $type) {
            [$status, $headers] = $this->get($kernel, '/reports/orders/export.' . $format, 'bob');
            self::assertSame(200, $status);
            self::assertSame($type, $headers['content-type']);
        }

        self::assertCount(3, $events);
        self::assertSame('user:alice', $events[0]->owner->key());
        self::assertSame(ReportFormat::Csv, $events[0]->format);
        self::assertSame('project:a1', $events[0]->context?->key());
        self::assertGreaterThan(0, $events[0]->rows);
        self::assertGreaterThan(0, $events[0]->bytes);
        self::assertNull($events[1]->context);
    }

    public function testUnreadableContextFallsBackToAllAccessible(): void
    {
        $kernel = $this->bootTeam();

        [$status, , $html] = $this->get($kernel, '/reports/orders?context=project:b1', 'alice');
        self::assertSame(200, $status);
        self::assertStringContainsString('data-testid="report-kit-context-fallback"', $html);
        self::assertStringContainsString('project:a1', $html);
        self::assertStringContainsString('project:a2', $html);
        self::assertStringNotContainsString('project:b1', $html);
    }

    public function testListenerCanDenyOrGrantWithinTheScope(): void
    {
        $kernel     = $this->bootTeam();
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ReportKitEvents::REPORT_ACCESS_CHECK, static function (ReportAccessCheckEvent $event): void {
            match ($event->owner->id) {
                'bob'   => $event->deny(),
                'alice' => $event->type === 'revenue' ? $event->grant() : null,
                default => null,
            };
        });

        [$status] = $this->get($kernel, '/reports/orders', 'bob');
        self::assertSame(403, $status);
        [, , $html] = $this->get($kernel, '/reports/revenue', 'alice');
        self::assertStringContainsString('Apollo', $html, 'grant opens the scope');
        self::assertStringNotContainsString('project:zz', $html, 'never more than the scope');
    }

    public function testRateLimitsAnswer429(): void
    {
        $kernel = $this->bootTeam(['limits' => ['exports' => ['limit' => 1, 'interval' => '1 hour'], 'views' => ['limit' => 1, 'interval' => '1 hour']]]);

        self::assertSame(200, $this->get($kernel, '/reports/orders', 'alice')[0]);
        [$status, $headers] = $this->get($kernel, '/reports/orders', 'alice');
        self::assertSame(429, $status);
        self::assertGreaterThan(0, (int) $headers['retry-after']);
        self::assertSame(200, $this->get($kernel, '/reports/orders', 'bob')[0], 'per person');
        self::assertSame(429, $this->get($kernel, '/reports', 'bob')[0], 'the list counts too');
        self::assertSame(200, $this->get($kernel, '/reports', 'root')[0]);
        self::assertSame(429, $this->get($kernel, '/reports/orders', 'root')[0]);

        self::assertSame(200, $this->get($kernel, '/reports/orders/export.csv', 'alice')[0]);
        self::assertSame(429, $this->get($kernel, '/reports/orders/export.csv', 'alice')[0]);
    }

    public function testPersonalTypeRunsWithoutContext(): void
    {
        $kernel = $this->bootTeam();

        [$status, , $html] = $this->get($kernel, '/reports/personal', 'bob');
        self::assertSame(200, $status, $html);
        self::assertStringContainsString('user:bob', $html);
        self::assertStringNotContainsString('name="context"', $html);
    }
}
