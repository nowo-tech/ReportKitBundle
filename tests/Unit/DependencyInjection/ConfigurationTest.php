<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ReportKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsMatchTheContract(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['enabled']);
        self::assertSame(['entity_manager' => 'default', 'table_prefix' => 'nowo_', 'auto_mapping' => true, 'mapping' => 'xml'], $config['doctrine']);
        self::assertSame(['type' => 'user', 'class' => null, 'id_field' => 'id', 'resolver' => null, 'directory' => null], $config['owner']);
        self::assertSame(['order' => [], 'disabled' => []], $config['types']);
        self::assertSame(['resolver' => null, 'access_checker' => null], $config['scope']);
        self::assertSame([
            'csv'  => ['enabled' => true],
            'xlsx' => ['enabled' => 'auto'],
            'pdf'  => ['enabled' => 'auto', 'paper' => 'A4', 'template' => '@NowoReportKit/pdf/report.html.twig'],
            'json' => ['enabled' => false],
        ], $config['formats']);
        self::assertSame('report', $config['filename_prefix']);
        self::assertSame(['resolver' => null], $config['brand']);
        self::assertSame([
            'cache_pool'           => 'cache.rate_limiter',
            'exports'              => ['limit' => 20, 'interval' => '10 minutes'],
            'views'                => ['limit' => 60, 'interval' => '1 minute'],
            'max_rows_per_section' => 50000,
            'pdf_max_rows'         => 120,
            'pdf_max_cell'         => 180,
            'render_seconds'       => 25,
            'max_pdf_html_bytes'   => 5242880,
        ], $config['limits']);
        self::assertSame(['enabled' => true, 'max_per_owner' => 10, 'frequencies' => ['daily', 'weekly', 'monthly'], 'default_hour' => 7, 'allow_hour_choice' => true], $config['subscriptions']);
        self::assertSame(['enabled' => false, 'cron' => '5 * * * *', 'default_timezone' => 'UTC', 'batch' => 200, 'max_failures' => 3, 'stale_claim_minutes' => 30], $config['schedule']);
        self::assertSame(['enabled' => 'auto', 'template' => '@NowoReportKit/email/scheduled_report.html.twig', 'max_attachment_bytes' => 8388608], $config['mail']);
        self::assertSame(['base_url' => null], $config['links']);
        self::assertSame(['run_days' => 90, 'batch_size' => 500], $config['retention']);
        self::assertSame(['max_seconds' => 50], $config['messenger']);
        self::assertSame(['user_kit' => 'auto', 'notify_kit' => 'auto', 'audit_kit' => 'auto', 'mail_kit' => 'auto', 'controller_kit' => 'auto', 'api_token' => 'auto', 'instance_settings' => false], $config['bridges']);
        self::assertSame([
            'enabled'         => false,
            'path_prefix'     => '/reports',
            'layout_template' => null,
            'templates'       => ['index' => null, 'show' => null, 'sections' => null, 'schedule_dialog' => null],
            'security'        => ['access_roles' => ['ROLE_USER']],
        ], $config['web_ui']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/admin/report-subscriptions', 'security' => ['access_roles' => ['ROLE_ADMIN'], 'access_checker' => null, 'allow_unauthenticated' => false]], $config['admin_ui']);
        self::assertSame(['enabled' => false, 'prefix' => '/api'], $config['api']);
        self::assertNull($config['settings_provider']);
    }

    public function testAFullHostConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'enabled'         => true,
            'owner'           => ['type' => 'member', 'resolver' => 'app.owners'],
            'types'           => ['order' => ['executive_summary', 'uptime'], 'disabled' => ['workload']],
            'scope'           => ['resolver' => 'app.scope', 'access_checker' => 'app.report_access'],
            'formats'         => ['csv' => ['enabled' => false], 'xlsx' => ['enabled' => false], 'pdf' => ['paper' => 'letter'], 'json' => ['enabled' => true]],
            'filename_prefix' => 'beacon-report',
            'limits'          => ['exports' => ['limit' => 5, 'interval' => '1 hour']],
            'subscriptions'   => ['frequencies' => ['weekly'], 'default_hour' => 0],
            'schedule'        => ['enabled' => true, 'cron' => '*/5 * * * *', 'default_timezone' => 'America/New_York'],
            'mail'            => ['enabled' => true],
            'links'           => ['base_url' => 'http://localhost:8223'],
            'bridges'         => ['instance_settings' => 'auto', 'notify_kit' => false],
            'web_ui'          => ['enabled' => true, 'path_prefix' => '/analytics/reports'],
            'admin_ui'        => ['enabled' => true],
            'api'             => ['enabled' => true],
        ]);

        self::assertSame('beacon-report', $config['filename_prefix']);
        self::assertSame(['weekly'], $config['subscriptions']['frequencies']);
        self::assertSame('America/New_York', $config['schedule']['default_timezone']);
        self::assertSame('auto', $config['bridges']['instance_settings']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidValuesAreRefusedWithThePath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown key' => [['charts' => true], 'Unrecognized option "charts"'];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => '9x']], 'nowo_report_kit.doctrine.table_prefix'];
        yield 'owner type' => [['owner' => ['type' => 'Owner']], 'nowo_report_kit.owner.type'];
        yield 'empty service id' => [['scope' => ['resolver' => '']], 'Expected a service id or null'];
        yield 'tri-state' => [['formats' => ['pdf' => ['enabled' => 'maybe']]], 'Expected true, false or "auto"'];
        yield 'paper' => [['formats' => ['pdf' => ['paper' => 'A5']]], 'nowo_report_kit.formats.pdf.paper'];
        yield 'file name prefix' => [['filename_prefix' => 'My Report'], 'Expected a file name prefix'];
        yield 'rate interval' => [['limits' => ['views' => ['interval' => '1 week']]], 'Expected an interval such as "1 minute"'];
        yield 'frequency' => [['subscriptions' => ['frequencies' => ['yearly']]], 'nowo_report_kit.subscriptions.frequencies'];
        yield 'hour' => [['subscriptions' => ['default_hour' => -1]], 'nowo_report_kit.subscriptions.default_hour'];
        yield 'cron' => [['schedule' => ['cron' => '@hourly']], 'Expected a five-field cron expression'];
        yield 'time zone' => [['schedule' => ['default_timezone' => 'CEST+1']], 'Expected a time zone identifier'];
        yield 'base url' => [['links' => ['base_url' => 'example.com']], 'Expected null or an absolute http(s) URL'];
        yield 'path' => [['web_ui' => ['path_prefix' => '/reports/']], 'Expected an absolute path without a trailing slash'];
        yield 'admin path' => [['admin_ui' => ['path_prefix' => '']], 'Expected an absolute path without a trailing slash'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
