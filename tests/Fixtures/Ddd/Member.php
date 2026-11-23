<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

/**
 * DDD host: a plain member (no framework type, XML-mapped on the `ddd` entity manager).
 */
class Member
{
    private bool $suspended = false;

    public function __construct(private string $memberId, private string $mail)
    {
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function mail(): string
    {
        return $this->mail;
    }

    public function suspended(): bool
    {
        return $this->suspended;
    }

    public function suspend(): void
    {
        $this->suspended = true;
    }
}
