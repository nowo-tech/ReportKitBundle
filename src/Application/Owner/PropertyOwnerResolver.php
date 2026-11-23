<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Owner;

use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Stringable;

use function is_callable;
use function is_int;
use function is_string;
use function property_exists;
use function ucfirst;

/**
 * Default {@see OwnerResolverInterface} (`owner.resolver: null`): `owner.type` plus the user's `owner.id_field`, read
 * through a public `get<Field>()`, `<field>()` or a public property, as a string (an int or a `Stringable` such as a Uid is
 * converted). A user that is not an instance of `owner.class` (when set), or whose identifier is missing or not a
 * valid {@see OwnerRef} id, has no owner. Stateless.
 */
final readonly class PropertyOwnerResolver implements OwnerResolverInterface
{
    /**
     * @param string $type `owner.type`
     * @param class-string|null $class `owner.class`; null = any user object
     * @param string $idField `owner.id_field`
     */
    public function __construct(private string $type, private ?string $class, private string $idField)
    {
    }

    /**
     * The owner of this user, or null.
     */
    public function ownerOf(object $user): ?OwnerRef
    {
        if ($this->class !== null && !$user instanceof $this->class) {
            return null;
        }
        $id = $this->read($user);
        if (is_int($id) || $id instanceof Stringable) {
            $id = (string) $id;
        }
        if (!is_string($id)) {
            return null;
        }
        try {
            return new OwnerRef($this->type, $id);
        } catch (InvalidReportArgumentException) {
            return null;
        }
    }

    private function read(object $user): mixed
    {
        $getter = 'get' . ucfirst($this->idField);
        if (is_callable([$user, $getter])) {
            return $user->{$getter}();
        }
        if (is_callable([$user, $this->idField])) {
            return $user->{$this->idField}();
        }
        if (property_exists($user, $this->idField) && isset($user->{$this->idField})) {
            return $user->{$this->idField};
        }

        return null;
    }
}
