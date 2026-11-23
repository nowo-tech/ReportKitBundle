<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function max;
use function time;

/**
 * Rate limits of the pages (contract §6.4): `limits.views` per person on the report page, `limits.exports` per person
 * on the export routes. The state lives in the host's `limits.cache_pool` (shared by every worker, nothing in process
 * memory), keyed by the owner reference. Over the limit → 429 with `Retry-After`.
 *
 * @internal
 */
final readonly class ReportRateLimiter
{
    /**
     * @param RateLimiterFactory $views `limits.views`
     * @param RateLimiterFactory $exports `limits.exports`
     */
    public function __construct(private RateLimiterFactory $views, private RateLimiterFactory $exports)
    {
    }

    /**
     * Counts one screen render.
     *
     * @throws TooManyRequestsHttpException
     */
    public function view(OwnerRef $owner): void
    {
        $this->consume($this->views, $owner);
    }

    /**
     * Counts one export.
     *
     * @throws TooManyRequestsHttpException
     */
    public function export(OwnerRef $owner): void
    {
        $this->consume($this->exports, $owner);
    }

    private function consume(RateLimiterFactory $factory, OwnerRef $owner): void
    {
        $limit = $factory->create($owner->key())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many report requests; try again later.');
        }
    }
}
