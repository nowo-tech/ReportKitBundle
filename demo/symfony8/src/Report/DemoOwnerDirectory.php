<?php

declare(strict_types=1);

namespace App\Report;

use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Demo people directory (S3): the two in-memory demo users, their demo address (caught by Mailpit), locale and time
 * zone. A real host reads its user table (or sets `owner.class` for the bundle's Doctrine directory).
 */
final readonly class DemoOwnerDirectory implements OwnerDirectoryInterface
{
    /** Demo user => [e-mail, locale, time zone]. */
    public const PEOPLE = [
        'alice' => ['alice@demo.test', 'en', 'Europe/Madrid'],
        'bob'   => ['bob@demo.test', 'es', 'Europe/Madrid'],
    ];

    public function userOf(OwnerRef $owner): ?object
    {
        return $owner->type === 'user' && isset(self::PEOPLE[$owner->id]) ? new InMemoryUser($owner->id, null, ['ROLE_USER']) : null;
    }

    public function isActive(OwnerRef $owner): bool
    {
        return $this->userOf($owner) !== null;
    }

    public function email(OwnerRef $owner): ?string
    {
        return $this->userOf($owner) !== null ? self::PEOPLE[$owner->id][0] : null;
    }

    public function locale(OwnerRef $owner): ?string
    {
        return $this->userOf($owner) !== null ? self::PEOPLE[$owner->id][1] : null;
    }

    public function timezone(OwnerRef $owner): ?string
    {
        return $this->userOf($owner) !== null ? self::PEOPLE[$owner->id][2] : null;
    }

    public function displayName(OwnerRef $owner): ?string
    {
        return $this->userOf($owner) !== null ? ucfirst($owner->id) : null;
    }
}
