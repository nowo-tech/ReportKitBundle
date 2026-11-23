<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

use function in_array;
use function is_array;

/**
 * Demo-only sign-in without a password: the home page form (POST /sign-in, CSRF) picks alice or bob; anonymous
 * visitors of /reports are sent to that form. Never use this in an application.
 */
final class DemoSignInAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === 'demo_sign_in';
    }

    public function authenticate(Request $request): Passport
    {
        $data = $request->request->all('demo_sign_in');
        $user = is_array($data) ? ($data['user'] ?? null) : null;
        if (!in_array($user, ['alice', 'bob'], true)) {
            throw new CustomUserMessageAuthenticationException('Pick a demo user.');
        }

        return new SelfValidatingPassport(new UserBadge($user), [new CsrfTokenBadge('demo_sign_in', (string) ($data['_token'] ?? ''))]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urls->generate('nowo_report_kit_index'), Response::HTTP_SEE_OTHER);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse($this->urls->generate('demo_home'), Response::HTTP_SEE_OTHER);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('demo_home', ['signin' => 1]), Response::HTTP_SEE_OTHER);
    }
}
