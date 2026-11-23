<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Cache;

use LogicException;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

use function implode;
use function sprintf;

/**
 * Fails `cache:warmup` when a report type declares a permission but no access checker is configured
 * (`scope.access_checker: null`, contract §4.3): those types would stay closed for everyone. Registered only in that
 * case. Not optional, so a deploy that warms the cache shows the gap; the builder refuses the types anyway.
 */
final readonly class PermissionCheckerWarmer implements CacheWarmerInterface
{
    /**
     * @param ReportTypeRegistryInterface $types the report types
     */
    public function __construct(private ReportTypeRegistryInterface $types)
    {
    }

    /**
     * Mandatory.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $keys = [];
        foreach ($this->types->all() as $key => $type) {
            if ($type->permission() !== null) {
                $keys[] = $key;
            }
        }
        if ($keys !== []) {
            throw new LogicException(sprintf('The report types "%s" declare a permission but "nowo_report_kit.scope.access_checker" is not set: configure a ReportAccessCheckerInterface service (contract §4.3).', implode('", "', $keys)));
        }

        return [];
    }
}
