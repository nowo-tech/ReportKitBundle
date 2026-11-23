<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Subscription;

use Nowo\ReportKitBundle\Application\Run\ReportLinksInterface;

use function http_build_query;

/**
 * Links double: fixed absolute URLs.
 */
final class StubLinks implements ReportLinksInterface
{
    public function reportUrl(string $type, array $query): ?string
    {
        return 'https://reports.example.test/reports/' . $type . '?' . http_build_query($query);
    }

    public function subscriptionsUrl(): ?string
    {
        return 'https://reports.example.test/reports';
    }
}
