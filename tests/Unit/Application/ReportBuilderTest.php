<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\ReportKitBundle\Application\Access\UnconfiguredReportAccessChecker;
use Nowo\ReportKitBundle\Application\ReportBuilder;
use Nowo\ReportKitBundle\Application\ReportParametersResolver;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Application\Scope\NoContextScopeResolver;
use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportAccessCheckEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\RevenueReportType;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\Translator;

use function array_keys;
use function count;
use function range;

/**
 * Contract §6.1: the scope is the ceiling, the checker narrows (exact keys), listeners grant within the scope or deny,
 * unknown contexts fall back to all accessible, a required context opens on the first one; bounded scope.
 */
final class ReportBuilderTest extends TestCase
{
    public function testAvailableListsWhatTheOwnerMayOpen(): void
    {
        $builder = $this->builder();

        self::assertSame(['orders', 'personal', 'revenue'], array_keys($builder->available($this->owner('alice'), new stdClass())));
        // root: no context; revenue needs one (refused), orders and personal run context-free.
        self::assertSame(['orders', 'personal'], array_keys($builder->available($this->owner('root'), new stdClass())));
    }

    public function testCheckerIsIntersectedWithTheScope(): void
    {
        $prepared = $this->builder()->prepare('revenue', $this->owner('alice'), new stdClass(), ['context' => 'project:a2'], false, 'de', 'Europe/Madrid');

        self::assertSame(['project:a1'], array_map(static fn (ContextRef $c): string => $c->key(), $prepared->request->accessible));
        self::assertSame('project:a1', $prepared->request->parameters->context?->key(), 'required context: first accessible');
        self::assertSame('Alpha', $prepared->request->contextLabel);
        self::assertCount(1, $prepared->choices);
        self::assertSame('de', $prepared->request->locale);
        self::assertSame('Europe/Madrid', $prepared->request->timezone);
        self::assertFalse($prepared->request->scheduled);
    }

    public function testUnknownContextFallsBackAndBadLocaleOrZoneUseDefaults(): void
    {
        $prepared = $this->builder(timezone: 'Europe/Lisbon')->prepare('orders', $this->owner('alice'), new stdClass(), ['context' => 'project:b1'], true, '../x', 'Mars/Base');

        self::assertNull($prepared->request->parameters->context);
        self::assertTrue($prepared->request->parameters->contextUnavailable);
        self::assertNull($prepared->request->contextLabel);
        self::assertSame('en', $prepared->request->locale);
        self::assertSame('Europe/Lisbon', $prepared->request->timezone);
        self::assertTrue($prepared->request->scheduled);
        self::assertCount(2, $prepared->request->accessible);
    }

    public function testUnknownTypeAndRefusals(): void
    {
        $builder = $this->builder();

        try {
            $builder->build('nope', $this->owner('alice'), new stdClass(), []);
            self::fail('unknown');
        } catch (UnknownReportTypeException $e) {
            self::assertSame('nope', $e->type);
        }
        foreach (['carol' => ReportAccessDeniedException::NO_PERMISSION, 'root' => ReportAccessDeniedException::NO_CONTEXT] as $owner => $reason) {
            try {
                $builder->prepare('revenue', $this->owner($owner), new stdClass(), []);
                self::fail($owner);
            } catch (ReportAccessDeniedException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        // No scope at all and no context-free grant: refused.
        $closed = $this->builder(scope: new NoContextScopeResolver(), access: new UnconfiguredReportAccessChecker());
        $this->expectExceptionObject(new ReportAccessDeniedException(ReportAccessDeniedException::NO_PERMISSION));
        $closed->prepare('revenue', $this->owner('alice'), new stdClass(), []);
    }

    public function testBuildRunsTheType(): void
    {
        $report = $this->builder()->build('personal', $this->owner('bob'), new stdClass(), []);

        self::assertSame([['user:bob']], $report->sections[0]->rows);
    }

    public function testListenersGrantWithinTheScopeOrDeny(): void
    {
        $events  = new EventDispatcher();
        $builder = $this->builder(events: $events);
        $seen    = [];
        $events->addListener(ReportKitEvents::REPORT_ACCESS_CHECK, static function (ReportAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->type, $event->permission, count($event->scope), count($event->allowed), $event->allowedWithoutContext, $event->scheduled, $event->decision()];
            if ($event->owner->id === 'alice') {
                $event->grant();
            }
            if ($event->owner->id === 'bob') {
                $event->deny();
            }
        });

        $prepared = $builder->prepare('revenue', $this->owner('alice'), new stdClass(), [], true);
        self::assertCount(2, $prepared->request->accessible, 'grant opens the scope, never project:zz');
        self::assertSame([['revenue', 'revenue.view', 2, 1, false, true, ReportAccessCheckEvent::DEFAULT]], $seen);

        try {
            $builder->prepare('orders', $this->owner('bob'), new stdClass(), []);
            self::fail('denied');
        } catch (ReportAccessDeniedException $e) {
            self::assertSame(ReportAccessDeniedException::DENIED_BY_LISTENER, $e->reason);
        }
        // Grant without scope: the context-free build is allowed.
        $free = $this->builder(scope: new NoContextScopeResolver(), access: new UnconfiguredReportAccessChecker(), events: $events);
        self::assertSame([], $free->prepare('orders', $this->owner('alice'), new stdClass(), [])->choices);
    }

    public function testScopeAnswerIsCheckedDeduplicatedAndBounded(): void
    {
        $dupes = $this->builder(scope: $this->scope([
            new ContextChoice(new ContextRef('project', 'a1'), 'First'),
            new ContextChoice(new ContextRef('project', 'a1'), 'Second'),
        ]));
        $prepared = $dupes->prepare('orders', $this->owner('alice'), new stdClass(), ['context' => 'project:a1']);
        self::assertCount(1, $prepared->choices);
        self::assertSame('First', $prepared->request->contextLabel);

        $many = [];
        foreach (range(0, ReportScopeResolverInterface::MAX_CONTEXTS) as $i) {
            $many[] = new ContextChoice(new ContextRef('project', 'p' . $i), 'P' . $i);
        }
        try {
            $this->builder(scope: $this->scope($many))->prepare('orders', $this->owner('alice'), new stdClass(), []);
            self::fail('bounded');
        } catch (ReportLimitExceededException $e) {
            self::assertSame(ReportLimitExceededException::SCOPE_CONTEXTS, $e->limit);
        }

        $this->expectException(LogicException::class);
        $this->builder(scope: $this->scope([new ContextRef('project', 'a1')]))->prepare('orders', $this->owner('alice'), new stdClass(), []);
    }

    private function builder(?ReportScopeResolverInterface $scope = null, ?ReportAccessCheckerInterface $access = null, ?EventDispatcher $events = null, string $timezone = 'UTC'): ReportBuilder
    {
        $translator = new Translator('en');
        $types      = new ReportTypeRegistry([new OrdersReportType($translator), new RevenueReportType($translator), new PersonalReportType($translator)]);

        return new ReportBuilder($types, new ReportParametersResolver(), $scope ?? new TeamScopeResolver(), $access ?? new TeamAccessChecker(), new MockClock('2026-11-23 10:00:00'), $timezone, $events);
    }

    private function owner(string $id): OwnerRef
    {
        return new OwnerRef('user', $id);
    }

    /**
     * @param list<mixed> $choices
     */
    private function scope(array $choices): ReportScopeResolverInterface
    {
        return new class($choices) implements ReportScopeResolverInterface {
            /** @param list<mixed> $choices */
            public function __construct(private readonly array $choices)
            {
            }

            public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
            {
                /* @var list<ContextChoice> */
                return $this->choices;
            }
        };
    }
}
