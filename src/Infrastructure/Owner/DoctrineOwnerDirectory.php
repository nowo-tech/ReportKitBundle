<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Owner;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;

use function is_bool;
use function is_string;
use function method_exists;

/**
 * Default {@see OwnerDirectoryInterface} of a standard host (contract §4.3): the user of `owner.class` whose
 * `owner.id_field` is the owner id, read through the host's Doctrine manager for that class (read only: never
 * persisted, flushed or cleared). Owners of another type than `owner.type` have no user.
 *
 * Conventions, each only when the user class has the method: `getEmail()`, `getLocale()`, `getTimezone()`,
 * `isEnabled()` / `isActive()` (false = inactive), `getDisplayName()` / `getName()` / `getUserIdentifier()`.
 * Anything else: plug a directory of your own (`owner.directory`). The runner re-checks the found user through the
 * owner resolver (exact owner reference), so a folding collation of the host's user table cannot hand over another
 * person's account (REQ-SEC-006 rule 4). Stateless; one lookup per call.
 */
final readonly class DoctrineOwnerDirectory implements OwnerDirectoryInterface
{
    /**
     * @param class-string $class `owner.class`
     * @param string $idField `owner.id_field`
     * @param string $ownerType `owner.type`
     */
    public function __construct(private ManagerRegistry $registry, private string $class, private string $idField = 'id', private string $ownerType = 'user')
    {
    }

    /**
     * {@inheritDoc}
     */
    public function userOf(OwnerRef $owner): ?object
    {
        if ($owner->type !== $this->ownerType) {
            return null;
        }
        $manager = $this->registry->getManagerForClass($this->class);
        if (!$manager instanceof ObjectManager) {
            return null;
        }

        return $manager->getRepository($this->class)->findOneBy([$this->idField => $owner->id]);
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(OwnerRef $owner): bool
    {
        $user = $this->userOf($owner);
        if ($user === null) {
            return false;
        }
        foreach (['isEnabled', 'isActive'] as $method) {
            if (method_exists($user, $method)) {
                $active = $user->{$method}();

                return !is_bool($active) || $active;
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function email(OwnerRef $owner): ?string
    {
        return $this->read($owner, ['getEmail']);
    }

    /**
     * {@inheritDoc}
     */
    public function locale(OwnerRef $owner): ?string
    {
        return $this->read($owner, ['getLocale']);
    }

    /**
     * {@inheritDoc}
     */
    public function timezone(OwnerRef $owner): ?string
    {
        return $this->read($owner, ['getTimezone']);
    }

    /**
     * {@inheritDoc}
     */
    public function displayName(OwnerRef $owner): ?string
    {
        return $this->read($owner, ['getDisplayName', 'getName', 'getUserIdentifier']);
    }

    /**
     * The first conventional getter that answers a non-empty string.
     *
     * @param list<string> $methods
     */
    private function read(OwnerRef $owner, array $methods): ?string
    {
        $user = $this->userOf($owner);
        if ($user === null) {
            return null;
        }
        foreach ($methods as $method) {
            if (method_exists($user, $method)) {
                $value = $user->{$method}();
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
