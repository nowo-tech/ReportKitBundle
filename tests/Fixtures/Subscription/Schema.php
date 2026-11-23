<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener;
use Psr\Log\LoggerInterface;

use function dirname;
use function str_contains;
use function sys_get_temp_dir;

/**
 * The bundle tables on a connection, created by the ORM schema tool from the XML mapping (or the attribute twins),
 * with the bundle's metadata listener (prefix, collation): the same path a host's `doctrine:schema:update` takes.
 */
final class Schema
{
    /**
     * A fresh in-memory SQLite connection.
     */
    public static function sqlite(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * An entity manager mapping the bundle classes on $connection.
     */
    public static function manager(Connection $connection, string $prefix = 'nowo_', string $mapping = 'xml', ?LoggerInterface $logger = null): EntityManager
    {
        $src    = dirname(__DIR__, 3) . '/src';
        $config = ORMSetup::createAttributeMetadataConfig([$src . '/Infrastructure/Doctrine/Entity'], true);
        if ($mapping !== 'attribute') {
            $config->setMetadataDriverImpl(new SimplifiedXmlDriver([$src . '/Resources/config/doctrine' => 'Nowo\\ReportKitBundle\\Domain'], '.orm.xml', true));
        }
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-report-kit-proxies');
            $config->setProxyNamespace('NowoReportKitTestProxies');
        }
        $manager = new EntityManager($connection, $config);
        $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new ReportKitMetadataListener($prefix, $logger));

        return $manager;
    }

    /**
     * Drops and creates the bundle tables; returns the entity manager used.
     */
    public static function create(Connection $connection, string $prefix = 'nowo_', string $mapping = 'xml'): EntityManager
    {
        $manager = self::manager($connection, $prefix, $mapping);
        $classes = $manager->getMetadataFactory()->getAllMetadata();
        $tool    = new SchemaTool($manager);
        foreach ([$prefix . 'report_run', $prefix . 'report_subscription', $prefix . 'report_owner_lock'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $tool->createSchema($classes);

        return $manager;
    }

    /**
     * Whether the SQL names one of the bundle tables.
     */
    public static function isBundleSql(string $sql, string $prefix = 'nowo_'): bool
    {
        return str_contains($sql, $prefix . 'report_');
    }
}
