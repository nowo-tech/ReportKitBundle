<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\ReportKitBundle\Application\Access\UnconfiguredReportAccessChecker;
use Nowo\ReportKitBundle\Application\Owner\PropertyOwnerResolver;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportExportedEvent;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Infrastructure\Cache\PermissionCheckerWarmer;
use Nowo\ReportKitBundle\Infrastructure\Security\AllowAllReportKitAccessChecker;
use Nowo\ReportKitBundle\Infrastructure\Security\ConfigurableReportKitAccessChecker;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\RevenueReportType;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\Translator;

use function str_repeat;

/**
 * The S2 defaults and small value objects: owner resolver, no-context scope, closed checker, area checkers, the
 * permission warmer, ContextChoice and the exported event.
 */
final class AccessSupportTest extends TestCase
{
    public function testPropertyOwnerResolverReadsTheConfiguredField(): void
    {
        $getter = new class {
            public function getUuid(): string
            {
                return 'u-1';
            }
        };
        $method = new class {
            public function uuid(): int
            {
                return 7;
            }
        };
        $property = new class {
            public Stringable $uuid;

            public function __construct()
            {
                $this->uuid = new class implements Stringable {
                    public function __toString(): string
                    {
                        return 'u-3';
                    }
                };
            }
        };
        $resolver = new PropertyOwnerResolver('user', null, 'uuid');

        self::assertSame('user:u-1', $resolver->ownerOf($getter)?->key());
        self::assertSame('user:7', $resolver->ownerOf($method)?->key());
        self::assertSame('user:u-3', $resolver->ownerOf($property)?->key());
        self::assertNull($resolver->ownerOf(new stdClass()), 'no identifier');
        self::assertNull((new PropertyOwnerResolver('user', $getter::class, 'uuid'))->ownerOf($method), 'wrong class');
        $bad = new class {
            public string $uuid = ' padded';
        };
        self::assertNull($resolver->ownerOf($bad), 'not a valid id');
        $array = new class {
            /** @var list<string> */
            public array $uuid = ['x'];
        };
        self::assertNull($resolver->ownerOf($array));
    }

    public function testDefaultsGrantNothingAndAreaCheckers(): void
    {
        $owner = new OwnerRef('user', 'a');
        self::assertSame([], (new NoContextScopeResolver())->accessibleContexts($owner, new stdClass(), 'orders'));
        $closed = new UnconfiguredReportAccessChecker();
        self::assertSame([], $closed->allows($owner, new stdClass(), 'orders', [new ContextRef('project', '1')]));
        self::assertFalse($closed->allowsWithoutContext($owner, new stdClass(), 'orders'));
        self::assertTrue((new AllowAllReportKitAccessChecker())->canAccess(new stdClass()));

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_REPORTS');
        self::assertTrue((new ConfigurableReportKitAccessChecker($auth, []))->canAccess(new stdClass()));
        self::assertTrue((new ConfigurableReportKitAccessChecker($auth, ['ROLE_X', 'ROLE_REPORTS']))->canAccess(new stdClass()));
        self::assertFalse((new ConfigurableReportKitAccessChecker($auth, ['ROLE_X']))->canAccess(new stdClass()));
    }

    public function testPermissionWarmerFailsOnlyWithPermissionTypes(): void
    {
        $translator = new Translator('en');
        $open       = new PermissionCheckerWarmer(new ReportTypeRegistry([new OrdersReportType($translator)]));
        self::assertFalse($open->isOptional());
        self::assertSame([], $open->warmUp('/unused'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"revenue"');
        (new PermissionCheckerWarmer(new ReportTypeRegistry([new RevenueReportType($translator)])))->warmUp('/unused');
    }

    public function testContextChoiceCleansAndBoundsItsLabel(): void
    {
        self::assertSame('Alpha  team', (new ContextChoice(new ContextRef('project', '1'), "Alpha\t team\n"))->label);
        foreach (["\n\t", str_repeat('x', 201)] as $label) {
            try {
                new ContextChoice(new ContextRef('project', '1'), $label);
                self::fail('refused');
            } catch (InvalidReportArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testExportedEventCarriesIdsOnly(): void
    {
        $event = new ReportExportedEvent(new OwnerRef('user', 'a'), 'orders', ReportFormat::Pdf, null, 3, 100);

        self::assertSame(['owner', 'type', 'format', 'context', 'rows', 'bytes'], array_keys(get_object_vars($event)));
    }
}
