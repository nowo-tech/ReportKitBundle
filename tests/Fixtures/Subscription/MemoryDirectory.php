<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * People directory of the tests: owner key => {user, active, email, locale, timezone, name}. Mutable on purpose (a
 * test deactivates a person between two runs).
 */
final class MemoryDirectory implements OwnerDirectoryInterface
{
    /** @var array<string, array{user: object|null, active: bool, email: string|null, locale: string|null, timezone: string|null, name: string|null}> */
    public array $people = [];

    public function add(string $id, ?string $email = null, ?string $locale = 'en', ?string $timezone = 'Europe/Madrid', bool $active = true, ?object $user = null, ?string $name = null): self
    {
        $this->people['user:' . $id] = [
            'user'     => $user ?? new InMemoryUser($id, null, ['ROLE_USER']),
            'active'   => $active,
            'email'    => $email ?? $id . '@example.test',
            'locale'   => $locale,
            'timezone' => $timezone,
            'name'     => $name ?? ucfirst($id),
        ];

        return $this;
    }

    public function userOf(OwnerRef $owner): ?object
    {
        return $this->people[$owner->key()]['user'] ?? null;
    }

    public function isActive(OwnerRef $owner): bool
    {
        return $this->people[$owner->key()]['active'] ?? false;
    }

    public function email(OwnerRef $owner): ?string
    {
        return $this->people[$owner->key()]['email'] ?? null;
    }

    public function locale(OwnerRef $owner): ?string
    {
        return $this->people[$owner->key()]['locale'] ?? null;
    }

    public function timezone(OwnerRef $owner): ?string
    {
        return $this->people[$owner->key()]['timezone'] ?? null;
    }

    public function displayName(OwnerRef $owner): ?string
    {
        return $this->people[$owner->key()]['name'] ?? null;
    }
}
