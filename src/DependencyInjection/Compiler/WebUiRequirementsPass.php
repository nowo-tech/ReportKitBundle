<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ReportKitBundle\Infrastructure\Security\AllowAllReportKitAccessChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twig\Environment;

use function is_array;
use function sprintf;

/**
 * Checks what the report pages need once every bundle is loaded (REQ-UI-002 rule 4): Twig, the form factory, UiKit
 * and FormKit, and SecurityBundle. Without SecurityBundle the container refuses to build, unless
 * `web_ui.security.allow_unauthenticated` is true (DEMO / DEV ONLY), which then opens the pages to a shared guest.
 *
 * @internal
 */
final class WebUiRequirementsPass implements CompilerPassInterface
{
    /** Bundles the pages render with. */
    private const BUNDLES = [
        'NowoUiKitBundle'   => 'nowo-tech/ui-kit-bundle',
        'NowoFormKitBundle' => 'nowo-tech/form-kit-bundle',
    ];

    /**
     * Applies the checks when the pages are registered.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('nowo_report_kit.web.controller')) {
            return;
        }
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        foreach (self::BUNDLES as $bundle => $package) {
            if (!is_array($bundles) || !isset($bundles[$bundle])) {
                throw new LogicException(sprintf('"nowo_report_kit.web_ui.enabled" needs %s (%s) registered in the kernel.', $bundle, $package));
            }
        }
        if (!$container->has(Environment::class) || !$container->has('form.factory')) {
            throw new LogicException('"nowo_report_kit.web_ui.enabled" needs Twig (symfony/twig-bundle) and the form component (framework.form).');
        }
        if ($container->has('security.token_storage') && $container->has('security.authorization_checker')) {
            return;
        }
        $config = $container->getParameter('nowo_report_kit.config');
        if (!is_array($config) || ($config['web_ui']['security']['allow_unauthenticated'] ?? false) !== true) {
            throw new LogicException('"nowo_report_kit.web_ui.enabled" needs SecurityBundle (symfony/security-bundle): the report pages are private. Set "web_ui.security.allow_unauthenticated: true" only for a demo (REQ-UI-002).');
        }
        if ($container->hasDefinition('nowo_report_kit.web.access_checker')) {
            // No authorization checker to ask: the demo-only guest opens the pages (per-type permissions still apply).
            $container->register('nowo_report_kit.web.access_checker', AllowAllReportKitAccessChecker::class);
        }
    }
}
