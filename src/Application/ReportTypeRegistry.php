<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application;

use InvalidArgumentException;
use LogicException;
use Nowo\ReportKitBundle\Contract\Guard;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;

use function array_flip;
use function get_debug_type;
use function ksort;
use function sprintf;

/**
 * The tagged report types in the configured menu order (`types.order`, the rest by key), without `types.disabled`.
 * Built once when the container creates it; immutable afterwards (worker-safe).
 */
final readonly class ReportTypeRegistry implements ReportTypeRegistryInterface
{
    /** @var array<string, ReportTypeInterface> */
    private array $types;

    /**
     * @param iterable<mixed> $types the services tagged `nowo_report_kit.type`
     * @param list<string> $order keys shown first, in this order
     * @param list<string> $disabled keys to hide
     *
     * @throws LogicException when a service is not a report type, a key is invalid or two types share a key
     */
    public function __construct(iterable $types, array $order = [], array $disabled = [])
    {
        $byKey = [];
        foreach ($types as $type) {
            if (!$type instanceof ReportTypeInterface) {
                throw new LogicException(sprintf('A service tagged "%s" must implement %s, %s given.', ReportTypeInterface::TAG, ReportTypeInterface::class, get_debug_type($type)));
            }
            $key = $type->key();
            try {
                Guard::key('Report type key', $key);
            } catch (InvalidArgumentException $e) {
                throw new LogicException(sprintf('Report type %s: %s', $type::class, $e->getMessage()), 0, $e);
            }
            if (isset($byKey[$key])) {
                throw new LogicException(sprintf('Two report types use the key "%s": %s and %s.', $key, $byKey[$key]::class, $type::class));
            }
            $byKey[$key] = $type;
        }
        $hidden  = array_flip($disabled);
        $ordered = [];
        foreach ($order as $key) {
            if (isset($byKey[$key]) && !isset($hidden[$key])) {
                $ordered[$key] = $byKey[$key];
            }
        }
        ksort($byKey);
        foreach ($byKey as $key => $type) {
            if (!isset($ordered[$key]) && !isset($hidden[$key])) {
                $ordered[$key] = $type;
            }
        }
        $this->types = $ordered;
    }

    /**
     * @return array<string, ReportTypeInterface>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The type with this key; null when unknown or disabled.
     */
    public function get(string $key): ?ReportTypeInterface
    {
        return $this->types[$key] ?? null;
    }
}
