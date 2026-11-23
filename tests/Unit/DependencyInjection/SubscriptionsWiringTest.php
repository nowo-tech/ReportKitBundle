<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\ReportMailerInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Nowo\ReportKitBundle\Infrastructure\Collation\ReportKitExactKeyColumns;
use Nowo\ReportKitBundle\Infrastructure\Mail\DisabledReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Mail\SymfonyReportMailer;
use Nowo\ReportKitBundle\Infrastructure\Messenger\RunScheduledReportHandler;
use Nowo\ReportKitBundle\Infrastructure\Owner\DoctrineOwnerDirectory;
use Nowo\ReportKitBundle\Infrastructure\Scheduler\ReportKitScheduleProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Wiring of S3: when the subscriptions are on (`auto` = DoctrineBundle + an owner directory), the refusals of
 * `true` without them, the mailer choice, the Scheduler recipe and the mapping prepend.
 */
final class SubscriptionsWiringTest extends TestCase
{
    public function testAutoStaysOffWithoutDoctrineOrADirectory(): void
    {
        $container = $this->load(['enabled' => true], false);
        self::assertFalse($container->getParameter('nowo_report_kit.subscriptions'));
        self::assertFalse($container->hasAlias(SubscriptionsInterface::class));

        $container = $this->load(['enabled' => true]);
        self::assertFalse($container->getParameter('nowo_report_kit.subscriptions'), 'Doctrine without a directory');
    }

    public function testAutoWithDoctrineAndAnOwnerClassRegistersEverything(): void
    {
        $container = $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class], 'schedule' => ['enabled' => true], 'web_ui' => ['enabled' => true]]);

        self::assertTrue($container->getParameter('nowo_report_kit.subscriptions'));
        self::assertTrue($container->hasAlias(SubscriptionsInterface::class));
        self::assertTrue($container->getAlias(SubscriptionsInterface::class)->isPublic());
        self::assertSame(DoctrineOwnerDirectory::class, $container->getDefinition('nowo_report_kit.owner_directory')->getClass());
        self::assertSame(SymfonyReportMailer::class, $container->getDefinition('nowo_report_kit.mailer')->getClass());
        self::assertTrue($container->hasDefinition(RunScheduledReportHandler::class));
        self::assertTrue($container->hasDefinition('nowo_report_kit.web.subscription_controller'));
        self::assertSame(['nowo_migrations_kit.exact_key_columns' => [[]]], $container->getDefinition(ReportKitExactKeyColumns::class)->getTags());
        self::assertSame([['name' => 'nowo_report_kit']], $container->getDefinition(ReportKitScheduleProvider::class)->getTag('scheduler.schedule_provider'));
        self::assertSame('nowo_report_kit.web.subscription_controller', $container->getDefinition('nowo_report_kit.web.route_loader')->getArgument(3));
    }

    public function testHostServicesReplaceTheDefaults(): void
    {
        $container = $this->load(['enabled' => true, 'subscriptions' => ['enabled' => true], 'owner' => ['directory' => 'app.directory'], 'mail' => ['mailer' => 'app.mailer']]);
        self::assertSame('app.directory', (string) $container->getAlias('nowo_report_kit.owner_directory'));
        self::assertSame('app.mailer', (string) $container->getAlias(ReportMailerInterface::class));
        self::assertTrue($container->hasAlias(OwnerDirectoryInterface::class));
    }

    public function testMailOffOrMissingNeverSends(): void
    {
        $off = $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class], 'mail' => ['enabled' => false]]);
        self::assertSame(DisabledReportMailer::class, $off->getDefinition('nowo_report_kit.mailer')->getClass());

        $missing = $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class]], true, static fn (string $class): bool => $class !== NowoReportKitExtension::SYMFONY_MAILER);
        self::assertSame(DisabledReportMailer::class, $missing->getDefinition('nowo_report_kit.mailer')->getClass());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('symfony/mailer is not installed');
        $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class], 'mail' => ['enabled' => true]], true, static fn (string $class): bool => $class !== NowoReportKitExtension::SYMFONY_MAILER);
    }

    public function testTheSchedulerRecipeNeedsCronExpressions(): void
    {
        $none = $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class], 'schedule' => ['enabled' => true]], true, static fn (string $class): bool => $class !== NowoReportKitExtension::SCHEDULER);
        self::assertFalse($none->hasDefinition(ReportKitScheduleProvider::class), 'no Scheduler: the commands only');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('dragonmantank/cron-expression');
        $this->load(['enabled' => true, 'owner' => ['class' => stdClass::class], 'schedule' => ['enabled' => true]], true, static fn (string $class): bool => $class !== NowoReportKitExtension::CRON_EXPRESSION);
    }

    public function testTrueWithoutDoctrineOrADirectoryRefusesToBuild(): void
    {
        try {
            $this->load(['enabled' => true, 'subscriptions' => ['enabled' => true], 'owner' => ['class' => stdClass::class]], false);
            self::fail('built without Doctrine');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('DoctrineBundle is not registered', $e->getMessage());
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no owner directory is configured');
        $this->load(['enabled' => true, 'subscriptions' => ['enabled' => true]]);
    }

    public function testTheMappingIsPrependedOnTheConfiguredManager(): void
    {
        foreach (['xml' => 'Nowo\ReportKitBundle\Domain', 'attribute' => 'Nowo\ReportKitBundle\Infrastructure\Doctrine\Entity'] as $mapping => $prefix) {
            $container = $this->container(true);
            $container->prependExtensionConfig('doctrine', ['orm' => []]);
            $container->prependExtensionConfig('nowo_report_kit', ['enabled' => true, 'owner' => ['class' => stdClass::class], 'doctrine' => ['entity_manager' => 'reports', 'mapping' => $mapping]]);
            (new NowoReportKitExtension())->prepend($container);
            $doctrine = $container->getExtensionConfig('doctrine')[0];
            self::assertSame($prefix, $doctrine['orm']['entity_managers']['reports']['mappings']['NowoReportKitBundle']['prefix']);
            self::assertSame($mapping, $doctrine['orm']['entity_managers']['reports']['mappings']['NowoReportKitBundle']['type']);
        }

        foreach ([[], ['enabled' => true, 'owner' => ['class' => stdClass::class], 'doctrine' => ['auto_mapping' => false]], ['enabled' => true]] as $config) {
            $container = $this->container(true);
            $container->prependExtensionConfig('doctrine', ['orm' => []]);
            $container->prependExtensionConfig('nowo_report_kit', $config);
            (new NowoReportKitExtension())->prepend($container);
            self::assertCount(1, $container->getExtensionConfig('doctrine'), 'nothing prepended');
        }
        $dbalOnly = $this->container(true);
        $dbalOnly->prependExtensionConfig('doctrine', ['dbal' => []]);
        $dbalOnly->prependExtensionConfig('nowo_report_kit', ['enabled' => true, 'owner' => ['class' => stdClass::class]]);
        (new NowoReportKitExtension())->prepend($dbalOnly);
        self::assertCount(1, $dbalOnly->getExtensionConfig('doctrine'), 'DBAL only: no ORM mapping');
        $noDoctrine = $this->container(false);
        (new NowoReportKitExtension())->prepend($noDoctrine);
        self::assertFalse($noDoctrine->hasExtension('doctrine'));
    }

    /**
     * @param array<string, mixed> $config
     * @param (callable(string): bool)|null $installed
     */
    private function load(array $config, bool $doctrine = true, ?callable $installed = null): ContainerBuilder
    {
        $container = $this->container($doctrine);
        (new NowoReportKitExtension($installed))->load([$config], $container);

        return $container;
    }

    private function container(bool $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($doctrine) {
            $container->registerExtension(new class extends Extension {
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return 'doctrine';
                }
            });
        }

        return $container;
    }
}
