<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * A ManagerRegistry over one DBAL connection named `default` and, optionally, one entity manager `default`.
 */
final class ConnectionRegistry extends AbstractManagerRegistry
{
    /** @var array<string, object> */
    private array $services;

    public function __construct(Connection $connection, ?EntityManagerInterface $manager = null)
    {
        $this->services = ['connection.default' => $connection];
        $managers       = [];
        if ($manager instanceof EntityManagerInterface) {
            $this->services['manager.default'] = $manager;
            $managers                          = ['default' => 'manager.default'];
        }
        parent::__construct('test', ['default' => 'connection.default'], $managers, 'default', 'default', Proxy::class);
    }

    protected function getService(string $name): object
    {
        return $this->services[$name];
    }

    protected function resetService(string $name): void
    {
    }

    public function getAliasNamespace(string $alias): string
    {
        return $alias;
    }
}
