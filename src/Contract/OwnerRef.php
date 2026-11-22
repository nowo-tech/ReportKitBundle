<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * The person a report is built for (and, from S3, the owner of a subscription): e.g. `('user', '<uuid>')`. A copy of
 * the 221-A `OwnerRef` (Q-O12 of 221-A): no Doctrine relation, ids compare exactly.
 */
final readonly class OwnerRef
{
    /** Longest identifier, in characters (the `owner_id` column is 128 binary). */
    public const MAX_ID_LENGTH = 128;

    /**
     * @param string $type `^[a-z][a-z0-9_]{0,31}$`
     * @param string $id 1–128 UTF-8 characters, no control or invisible characters, no white space at the edges
     */
    public function __construct(public string $type, public string $id)
    {
        Guard::key('OwnerRef type', $type);
        Guard::reference('OwnerRef id', $id, self::MAX_ID_LENGTH);
    }

    /**
     * `<type>:<id>`, e.g. `user:42`.
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
