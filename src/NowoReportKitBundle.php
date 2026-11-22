<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle;

use Nowo\ReportKitBundle\DependencyInjection\Compiler\RenderingRequirementsPass;
use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Reports and scheduled exports for Symfony: report types with parameter schemas, PDF / XLSX / CSV / JSON renderers, server-side SVG charts, on-screen report pages and per-owner scheduled e-mail subscriptions.
 *
 * S1: report model, type registry, parameter schema, CSV / XLSX / PDF / JSON renderers, SVG charts, the closed PDF
 * runner and the brand resolver, registered only when `nowo_report_kit.enabled` is true. Routes, pages, tables and
 * schedules come with the next slices (see docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoReportKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoReportKitBundle';

    /**
     * Registers the compiler pass that checks the translator and Twig requirements.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RenderingRequirementsPass());
    }

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
