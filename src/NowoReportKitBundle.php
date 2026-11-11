<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle;

use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Reports and scheduled exports for Symfony: report types with parameter schemas, PDF / XLSX / CSV / JSON renderers, server-side SVG charts, on-screen report pages and per-owner scheduled e-mail subscriptions.
 *
 * S0 scaffold: the bundle class and its strict configuration tree (`nowo_report_kit`). No service, route, listener or table
 * is registered yet; the slices S1 and later add them (see docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoReportKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoReportKitBundle';

    /**
     * The extension alias is `nowo_report_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoReportKitExtension();
        }

        return $this->extension;
    }
}
