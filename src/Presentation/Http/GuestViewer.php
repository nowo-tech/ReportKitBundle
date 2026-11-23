<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

/**
 * The user object of an anonymous visitor with `web_ui.security.allow_unauthenticated: true` (DEMO / DEV ONLY): owner
 * `guest:anonymous`, shared by every visitor. The host's scope resolver and access checker receive it.
 */
final readonly class GuestViewer
{
    /** Owner type of the guest. */
    public const OWNER_TYPE = 'guest';

    /** Owner id of the guest. */
    public const OWNER_ID = 'anonymous';
}
