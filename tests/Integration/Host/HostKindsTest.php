<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Host;

use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\FixedBrandResolver;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\HostSettingsProvider;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\LedgerAccess;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\PlainReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Smalot\PdfParser\Parser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_keys;
use function json_decode;
use function ob_get_clean;
use function ob_start;

use const JSON_THROW_ON_ERROR;

/**
 * REQ-ARCH-001: the same export path on a standard host (autoconfigured type extending the optional base, default
 * brand and settings) and on a DDD host (port implemented directly and tagged by hand, own brand resolver and settings
 * provider).
 */
final class HostKindsTest extends KernelTestCase
{
    public function testStandardHost(): void
    {
        $kernel = $this->bootKernel(['enabled' => true, 'filename_prefix' => 'acme'], services: static function (ContainerConfigurator $c): void {
            $c->services()->set(OrdersReportType::class)->autowire()->autoconfigure();
        });
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        [$status, $headers, $csv] = $this->get($http, '/export/orders/a.csv?context=project:a2&period=7');
        self::assertSame(200, $status);
        self::assertSame('attachment; filename=acme-orders-a2-2026-11-22.csv', $headers['content-disposition']);
        self::assertStringContainsString('project:a2,a,7', $csv);
        self::assertStringNotContainsString('project:a1', $csv);

        // ZE-I3: a context the owner cannot read falls back to all accessible and the file says "all".
        [, $headers, $csv] = $this->get($http, '/export/orders/a.csv?context=project:b1');
        self::assertSame('attachment; filename=acme-orders-all-2026-11-22.csv', $headers['content-disposition']);
        self::assertStringContainsString('Last 30 days · All accessible contexts (the requested context is not available)', $csv);
        self::assertStringContainsString('project:a1,a,30', $csv);
        self::assertStringNotContainsString('b1', $csv);

        [, $headers, $pdf] = $this->get($http, '/export/orders/b.pdf?locale=fr');
        self::assertSame('application/pdf', $headers['content-type']);
        $text = (new Parser())->parseContent($pdf)->getText();
        self::assertStringContainsString('Page 1 sur 1', $text);
        self::assertStringContainsString('project:b1', $text);

        [, $headers] = $this->get($http, '/export/orders/a.xlsx');
        self::assertSame('attachment; filename=acme-orders-all-2026-11-22.xlsx', $headers['content-disposition']);
        $registry = $kernel->getContainer()->get(ReportTypeRegistryInterface::class);
        self::assertInstanceOf(ReportTypeRegistryInterface::class, $registry);
        self::assertSame(['orders'], array_keys($registry->all()));
    }

    public function testDddHost(): void
    {
        $kernel = $this->bootKernel([
            'enabled'           => true,
            'formats'           => ['json' => ['enabled' => true]],
            'settings_provider' => 'app.report_settings',
            'brand'             => ['resolver' => 'app.report_brand'],
            'scope'             => ['access_checker' => 'app.report_access'],
        ], services: static function (ContainerConfigurator $c): void {
            $services = $c->services();
            $services->set('app.report_access', LedgerAccess::class);
            $services->set(PlainReportType::class)->tag(ReportTypeInterface::TAG);
            $services->set('app.report_settings', HostSettingsProvider::class);
            $services->set('app.report_brand', FixedBrandResolver::class);
        });
        $container = $kernel->getContainer();
        $http      = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(FixedBrandResolver::class, $container->get(ReportBrandResolverInterface::class));

        [$status, $headers, $json] = $this->get($http, '/export/ledger/b.json');
        self::assertSame(200, $status);
        self::assertSame('attachment; filename=report-ledger-b.json', $headers['content-disposition']);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['b']], $data['sections'][0]['rows']);

        // The host settings provider turns the PDF off, whatever the configuration says.
        [$status] = $this->get($http, '/export/ledger/b.pdf');
        self::assertSame(500, $status);
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function get(HttpKernelInterface $http, string $uri): array
    {
        $response = $http->handle(Request::create($uri), HttpKernelInterface::MAIN_REQUEST, true);
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
