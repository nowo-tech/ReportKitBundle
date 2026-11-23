<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application;

use DateTimeZone;
use LogicException;
use Nowo\ReportKitBundle\Contract\ContextChoice;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportAccessCheckEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PreparedReport;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportParametersResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_values;
use function count;
use function get_debug_type;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Default {@see ReportBuilderInterface} (contract §6.1): scope resolver → access checker → `ReportAccessCheckEvent` →
 * parameters → type. The scope resolver's contexts are the ceiling: the checker's answer is intersected with them
 * (exact keys) and a listener's grant opens them, never more. An unknown or unreadable context in the raw parameters
 * falls back to "all accessible" (never an error that reveals whether it exists); a type that requires a context
 * falls back to the first accessible one. Stateless: every call reads the current access.
 */
final readonly class ReportBuilder implements ReportBuilderInterface
{
    /**
     * @param ReportTypeRegistryInterface $types the report types
     * @param ReportParametersResolverInterface $parameters raw → resolved parameters
     * @param ReportScopeResolverInterface $scope the host's contexts per person and type
     * @param ReportAccessCheckerInterface $access the host's permission per type and context
     * @param ClockInterface $clock the build time
     * @param string $defaultTimezone `schedule.default_timezone`
     * @param EventDispatcherInterface|null $events dispatches {@see ReportAccessCheckEvent}
     */
    public function __construct(
        private ReportTypeRegistryInterface $types,
        private ReportParametersResolverInterface $parameters,
        private ReportScopeResolverInterface $scope,
        private ReportAccessCheckerInterface $access,
        private ClockInterface $clock,
        private string $defaultTimezone = 'UTC',
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * @return array<string, ReportTypeInterface>
     */
    public function available(OwnerRef $owner, object $user): array
    {
        $available = [];
        foreach ($this->types->all() as $key => $type) {
            try {
                $this->narrow($type, $owner, $user, false);
                $available[$key] = $type;
            } catch (ReportAccessDeniedException) {
                // Not listed: the list shows only what the viewer may open.
            }
        }

        return $available;
    }

    /**
     * @param array<array-key, mixed> $rawParameters
     */
    public function prepare(string $type, OwnerRef $owner, object $user, array $rawParameters, bool $scheduled = false, ?string $locale = null, ?string $timezone = null): PreparedReport
    {
        $reportType = $this->types->get($type) ?? throw new UnknownReportTypeException($type);
        $choices    = $this->narrow($reportType, $owner, $user, $scheduled);
        $accessible = [];
        foreach ($choices as $choice) {
            $accessible[] = $choice->ref;
        }
        $schema = $reportType->parameters();
        if ($schema->contextRequired && !$this->picks($rawParameters['context'] ?? null, $accessible)) {
            // A type that needs one context opens on the first accessible one (the filter form switches).
            $rawParameters['context'] = $accessible[0]->key();
        }
        $parameters = $this->parameters->resolve($schema, $rawParameters, $accessible);
        $label      = null;
        foreach ($choices as $choice) {
            if ($parameters->context instanceof ContextRef && $choice->ref->equals($parameters->context)) {
                $label = $choice->label;
            }
        }

        return new PreparedReport($reportType, new ReportRequest(
            $owner,
            $user,
            $parameters,
            $accessible,
            $this->locale($locale),
            $this->timezone($timezone),
            $this->clock->now(),
            $scheduled,
            $label,
        ), $choices);
    }

    /**
     * @param array<array-key, mixed> $rawParameters
     */
    public function build(string $type, OwnerRef $owner, object $user, array $rawParameters, bool $scheduled = false, ?string $locale = null, ?string $timezone = null): Report
    {
        $prepared = $this->prepare($type, $owner, $user, $rawParameters, $scheduled, $locale, $timezone);

        return $prepared->type->build($prepared->request);
    }

    /**
     * The context choices that grant the type to this person (empty = a context-free build is allowed).
     *
     * @return list<ContextChoice>
     */
    private function narrow(ReportTypeInterface $type, OwnerRef $owner, object $user, bool $scheduled): array
    {
        $key     = $type->key();
        $schema  = $type->parameters();
        $choices = $schema->context ? $this->choices($owner, $user, $key) : [];
        $scope   = [];
        foreach ($choices as $choice) {
            $scope[] = $choice->ref;
        }
        $permission = $type->permission();
        if ($permission === null) {
            $allowed     = $scope;
            $contextFree = true;
        } elseif ($scope === []) {
            $allowed     = [];
            $contextFree = $this->access->allowsWithoutContext($owner, $user, $key);
        } else {
            $allowed     = $this->intersect($scope, $this->access->allows($owner, $user, $key, $scope));
            $contextFree = false;
        }

        if ($this->events instanceof EventDispatcherInterface) {
            $event = new ReportAccessCheckEvent($owner, $key, $permission, $scope, $allowed, $contextFree, $scheduled);
            $this->events->dispatch($event, ReportKitEvents::REPORT_ACCESS_CHECK);
            if ($event->decision() === ReportAccessCheckEvent::DENY) {
                throw new ReportAccessDeniedException(ReportAccessDeniedException::DENIED_BY_LISTENER);
            }
            if ($event->decision() === ReportAccessCheckEvent::GRANT) {
                $allowed     = $scope;
                $contextFree = true;
            }
        }

        if ($scope !== [] ? $allowed === [] : !$contextFree) {
            throw new ReportAccessDeniedException(ReportAccessDeniedException::NO_PERMISSION);
        }
        if ($schema->contextRequired && $allowed === []) {
            throw new ReportAccessDeniedException(ReportAccessDeniedException::NO_CONTEXT);
        }

        $narrowed = [];
        foreach ($choices as $choice) {
            if (in_array($choice->ref->key(), $this->keys($allowed), true)) {
                $narrowed[] = $choice;
            }
        }

        return $narrowed;
    }

    /**
     * The scope resolver's answer, checked and without duplicates (the first label of a key wins).
     *
     * @return list<ContextChoice>
     */
    private function choices(OwnerRef $owner, object $user, string $type): array
    {
        $choices = [];
        foreach ($this->scope->accessibleContexts($owner, $user, $type) as $choice) {
            if (!$choice instanceof ContextChoice) {
                throw new LogicException(sprintf('%s::accessibleContexts() must return ContextChoice values, %s given.', $this->scope::class, get_debug_type($choice)));
            }
            $choices[$choice->ref->key()] ??= $choice;
        }
        if (count($choices) > ReportScopeResolverInterface::MAX_CONTEXTS) {
            throw new ReportLimitExceededException(ReportLimitExceededException::SCOPE_CONTEXTS, ReportScopeResolverInterface::MAX_CONTEXTS);
        }

        return array_values($choices);
    }

    /**
     * The contexts of `$scope` that the checker named (exact keys; anything else in the answer is ignored).
     *
     * @param list<ContextRef> $scope
     * @param array<array-key, mixed> $answer
     *
     * @return list<ContextRef>
     */
    private function intersect(array $scope, array $answer): array
    {
        $granted = [];
        foreach ($answer as $context) {
            if ($context instanceof ContextRef) {
                $granted[] = $context->key();
            }
        }
        $allowed = [];
        foreach ($scope as $context) {
            if (in_array($context->key(), $granted, true)) {
                $allowed[] = $context;
            }
        }

        return $allowed;
    }

    /**
     * @param list<ContextRef> $contexts
     *
     * @return list<string>
     */
    private function keys(array $contexts): array
    {
        $keys = [];
        foreach ($contexts as $context) {
            $keys[] = $context->key();
        }

        return $keys;
    }

    /**
     * @param list<ContextRef> $accessible
     */
    private function picks(mixed $raw, array $accessible): bool
    {
        return is_string($raw) && in_array($raw, $this->keys($accessible), true);
    }

    private function locale(?string $locale): string
    {
        return is_string($locale) && preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $locale) === 1 ? $locale : 'en';
    }

    private function timezone(?string $timezone): string
    {
        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : $this->defaultTimezone;
    }
}
