<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use Nowo\ReportKitBundle\Contract\OwnerRef;

/**
 * The signed-in person of one page request: the owner reference and the host's user object. Built per request by
 * {@see ReportKitGuard}, never kept.
 *
 * @internal
 */
final readonly class Viewer
{
    /**
     * @param OwnerRef $owner the person
     * @param object $user the host's user object
     */
    public function __construct(public OwnerRef $owner, public object $user)
    {
    }
}
