<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\Member;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\MemberDirectory;
use Nowo\ReportKitBundle\Tests\Fixtures\Ddd\MemberOwnerResolver;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Standard\User;
use Nowo\ReportKitBundle\Tests\Fixtures\Subscription\RecordingMailer;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

use function array_replace_recursive;
use function assert;
use function dirname;
use function getenv;
use function is_string;

/**
 * The two host kinds of the S3 functional suite (REQ-ARCH-001): a standard Symfony app (attribute `User` entity, the
 * bundle's XML mapping prepended on the default entity manager, the Doctrine owner directory of `owner.class`) and a
 * DDD host (plain XML-mapped `Member` on a second entity manager `ddd` with its own connection, `auto_mapping: false`
 * and the bundle's XML registered there by hand, prefix `ddd_`, own owner resolver and directory).
 */
final readonly class Host
{
    public const STANDARD = 'standard';
    public const DDD      = 'ddd';

    /** Monday 2026-11-23 06:00 UTC. */
    public const NOW = '2026-11-23 06:00:00';

    private function __construct(public string $kind)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function ddd(): self
    {
        return new self(self::DDD);
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI: MySQL 8.4, MariaDB 11.4, PostgreSQL 16) or a SQLite file.
     */
    public static function databaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(TestKernel::baseDir());

        return 'sqlite:///' . TestKernel::baseDir() . '/functional.sqlite';
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public function bundleConfig(array $override = []): array
    {
        $common = [
            'enabled'       => true,
            'subscriptions' => ['enabled' => true, 'max_per_owner' => 3],
            'schedule'      => ['enabled' => true],
            'scope'         => ['resolver' => TeamScopeResolver::class, 'access_checker' => TeamAccessChecker::class],
            'mail'          => ['mailer' => RecordingMailer::class],
        ];
        $kind = $this->kind === self::STANDARD
            ? ['owner' => ['class' => User::class]]
            : [
                'doctrine' => ['entity_manager' => 'ddd', 'auto_mapping' => false, 'table_prefix' => 'ddd_'],
                'owner'    => ['type' => 'member', 'resolver' => MemberOwnerResolver::class, 'directory' => MemberDirectory::class],
            ];

        return array_replace_recursive($common, $kind, $override);
    }

    /**
     * @return Closure(ContainerConfigurator): void
     */
    public function configure(string $url): Closure
    {
        $kind = $this->kind;

        return static function (ContainerConfigurator $container) use ($kind, $url): void {
            $root = dirname(__DIR__, 3);
            if ($kind === self::STANDARD) {
                $container->extension('doctrine', [
                    'orm' => ['mappings' => ['Fixtures' => ['type' => 'attribute', 'dir' => $root . '/tests/Fixtures/Standard', 'prefix' => 'Nowo\ReportKitBundle\Tests\Fixtures\Standard', 'is_bundle' => false]]],
                ]);
            } else {
                $container->extension('doctrine', [
                    'dbal' => ['connections' => ['ddd' => ['url' => $url]]],
                    'orm'  => [
                        'default_entity_manager' => 'default',
                        'entity_managers'        => [
                            'default' => ['connection' => 'default'],
                            'ddd'     => ['connection' => 'ddd', 'mappings' => [
                                'Members' => ['type' => 'xml', 'dir' => $root . '/tests/Fixtures/Ddd/mapping', 'prefix' => 'Nowo\ReportKitBundle\Tests\Fixtures\Ddd', 'is_bundle' => false],
                                'Reports' => ['type' => 'xml', 'dir' => $root . '/src/Resources/config/doctrine', 'prefix' => 'Nowo\ReportKitBundle\Domain', 'is_bundle' => false],
                            ]],
                        ],
                    ],
                ]);
                $container->services()->set(MemberOwnerResolver::class);
                $container->services()->set(MemberDirectory::class)->autowire();
            }
            $services = $container->services();
            $services->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
            $services->set(RecordingMailer::class)->public();
            $services->set(TeamScopeResolver::class);
            $services->set(TeamAccessChecker::class);
            $services->set(OrdersReportType::class)->autowire()->autoconfigure();
        };
    }

    public function entityManager(TestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager($this->kind === self::STANDARD ? 'default' : 'ddd');
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function tablePrefix(): string
    {
        return $this->kind === self::STANDARD ? 'nowo_' : 'ddd_';
    }

    /**
     * Drops and creates the host and bundle tables.
     */
    public function resetSchema(TestKernel $kernel): void
    {
        $em       = $this->entityManager($kernel);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function owner(string $id): OwnerRef
    {
        return new OwnerRef($this->kind === self::STANDARD ? 'user' : 'member', $id);
    }

    /**
     * @param non-empty-string $id
     */
    public function createUser(TestKernel $kernel, string $id): void
    {
        $em = $this->entityManager($kernel);
        $em->persist($this->kind === self::STANDARD ? new User($id, $id . '@example.test') : new Member($id, $id . '@example.test'));
        $em->flush();
        $em->clear();
    }

    public function deactivate(TestKernel $kernel, string $id): void
    {
        $em   = $this->entityManager($kernel);
        $user = $em->find($this->kind === self::STANDARD ? User::class : Member::class, $id);
        if ($user instanceof User) {
            $user->enabled = false;
        } elseif ($user instanceof Member) {
            $user->suspend();
        }
        $em->flush();
        $em->clear();
    }
}
