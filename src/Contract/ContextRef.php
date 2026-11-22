<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use function explode;
use function str_contains;

/**
 * What a report is scoped to: e.g. `('project', '42')`. No foreign key; the host's scope resolver says which contexts
 * a person may report on (contract §4.3, S2).
 */
final readonly class ContextRef
{
    /** Longest identifier, in characters. */
    public const MAX_ID_LENGTH = 64;

    /**
     * @param string $type `^[a-z][a-z0-9_]{0,31}$`
     * @param string $id 1–64 UTF-8 characters, no control or invisible characters, no white space at the edges
     */
    public function __construct(public string $type, public string $id)
    {
        Guard::key('ContextRef type', $type);
        Guard::reference('ContextRef id', $id, self::MAX_ID_LENGTH);
    }

    /**
     * Parses `<type>:<id>` (the query-string form, {@see self::key()}); null when the value is not a valid reference.
     */
    public static function tryFromKey(string $key): ?self
    {
        if (!str_contains($key, ':')) {
            return null;
        }
        [$type, $id] = explode(':', $key, 2);
        try {
            return new self($type, $id);
        } catch (Exception\InvalidReportArgumentException) {
            return null;
        }
    }

    /**
     * `<type>:<id>`, e.g. `project:42`.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Exact comparison of type and identifier (`===`).
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
