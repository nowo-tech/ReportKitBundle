<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Presentation;

use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Presentation\Http\RenderedFileResponder;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;

final class RenderedFileResponderTest extends TestCase
{
    public function testAPrivateNeverCachedAttachment(): void
    {
        $response = (new RenderedFileResponder())->respond(RenderedFile::fromString('report-orders-all-2026-11-22.csv', 'text/csv; charset=UTF-8', "a,b\n"));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('4', $response->headers->get('Content-Length'));
        self::assertSame('attachment; filename=report-orders-all-2026-11-22.csv', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));

        ob_start();
        $response->sendContent();
        self::assertSame("a,b\n", ob_get_clean());
    }
}
