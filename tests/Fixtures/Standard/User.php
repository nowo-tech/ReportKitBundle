<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Standard;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Standard host: an attribute-mapped user entity with the conventions the default owner directory reads
 * (`getEmail()`, `getLocale()`, `getTimezone()`, `isEnabled()`). String id, so `UserA` and `usera` coexist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 128)]
        /** @var non-empty-string */
        public string $id,
        #[ORM\Column(type: Types::STRING, length: 180)]
        public string $email,
        #[ORM\Column(type: Types::STRING, length: 16)]
        public string $locale = 'en',
        #[ORM\Column(type: Types::STRING, length: 64)]
        public string $timezone = 'Europe/Madrid',
        #[ORM\Column(type: Types::BOOLEAN)]
        public bool $enabled = true,
    ) {
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
