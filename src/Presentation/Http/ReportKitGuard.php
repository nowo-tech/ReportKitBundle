<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Area guard of the report pages (REQ-UI-002), called first by every action: a signed-in user (else
 * `AccessDeniedException`, which the firewall turns into its sign-in entry point), allowed by the
 * {@see ReportKitAccessCheckerInterface} (else 403), with an owner reference (else 403). With
 * `allow_unauthenticated: true` (DEMO / DEV ONLY) an anonymous visitor becomes the shared {@see GuestViewer}.
 * Reads the token per call; keeps nothing.
 *
 * @internal
 */
final readonly class ReportKitGuard
{
    /**
     * @param ReportKitAccessCheckerInterface $access the area checker
     * @param OwnerResolverInterface $owners user → owner reference
     * @param TokenStorageInterface|null $tokens the security token storage (null without SecurityBundle)
     * @param bool $allowUnauthenticated `web_ui.security.allow_unauthenticated`
     */
    public function __construct(
        private ReportKitAccessCheckerInterface $access,
        private OwnerResolverInterface $owners,
        private ?TokenStorageInterface $tokens = null,
        private bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * The viewer of this request, or an exception (anonymous → sign-in, refused → 403).
     *
     * @throws AccessDeniedException
     */
    public function viewer(): Viewer
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            if ($this->allowUnauthenticated) {
                return new Viewer(new OwnerRef(GuestViewer::OWNER_TYPE, GuestViewer::OWNER_ID), new GuestViewer());
            }

            throw new AccessDeniedException('Sign in to open the reports.');
        }
        if (!$this->access->canAccess($user)) {
            throw new AccessDeniedException('The reports are not available to this user.');
        }
        $owner = $this->owners->ownerOf($user);
        if (!$owner instanceof OwnerRef) {
            throw new AccessDeniedException('This user cannot own reports.');
        }

        return new Viewer($owner, $user);
    }
}
