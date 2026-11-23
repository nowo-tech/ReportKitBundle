<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;

/**
 * REQ-RUNTIME-002 on the pages: one kernel kept alive (no services resetter), 300 alternating list / page / CSV
 * requests by alice and bob in two locales. Every answer holds the viewer's own contexts only and its own locale;
 * memory stays flat.
 */
final class WorkerPagesLoopTest extends WebKernelTestCase
{
    private const REQUESTS = 300;

    public function testAlternatingViewersNeverSeeEachOther(): void
    {
        $kernel = $this->bootTeam(['limits' => ['views' => ['limit' => 10000, 'interval' => '1 hour'], 'exports' => ['limit' => 10000, 'interval' => '1 hour']]]);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $baseline = 0;
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $user    = $i % 2 === 0 ? 'alice' : 'bob';
            $own     = $user === 'alice' ? 'project:a1' : 'project:b1';
            $other   = $user === 'alice' ? 'project:b1' : 'project:a1';
            $uri     = ['/reports', '/reports/orders', '/reports/orders/export.csv'][$i % 3];
            $request = Request::create($uri, 'GET', [], [], [], ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);
            $request->setLocale($i % 4 < 2 ? 'en' : 'de');
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
            $http->terminate($request, $response);

            self::assertSame(200, $response->getStatusCode(), 'request ' . $i);
            if ($uri !== '/reports') {
                self::assertStringContainsString($own, $body, 'request ' . $i . ' holds its own context');
            }
            self::assertStringNotContainsString($other, $body, 'request ' . $i . ' never holds the other viewer\'s context');
            if ($i === 30) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' page requests');
    }
}
