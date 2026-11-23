<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Run;

/**
 * Absolute links of a scheduled mail (contract §6.3, REQ-AUTH-011): built from `links.base_url`, else the router's
 * `default_uri`, never from a request Host.
 *
 * @internal
 */
interface ReportLinksInterface
{
    /**
     * The report page with these parameters; null when the pages are off.
     *
     * @param array<string, bool|int|string|null> $query
     */
    public function reportUrl(string $type, array $query): ?string;

    /**
     * The list page with the owner's subscriptions; null when the pages are off.
     */
    public function subscriptionsUrl(): ?string;
}
