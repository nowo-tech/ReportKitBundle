<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ReportKitBundle\Contract\PdfDocumentRendererInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_diff;
use function array_values;
use function is_array;

/**
 * Checks what the renderers need once every bundle is loaded: a translator (always), and Twig for the PDF. Without
 * Twig, `formats.pdf.enabled: true` refuses to build and `auto` turns the PDF off (and the PDF runner with it).
 *
 * @internal
 */
final class RenderingRequirementsPass implements CompilerPassInterface
{
    /**
     * Applies the checks when the bundle is enabled.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->getParameter('nowo_report_kit.enabled') !== true) {
            return;
        }
        if (!$container->has(TranslatorInterface::class)) {
            throw new LogicException('NowoReportKitBundle needs a translator: enable "framework.translator" (symfony/translation).');
        }
        if ($container->has(Environment::class)) {
            return;
        }
        $config = $container->getParameter('nowo_report_kit.config');
        if (is_array($config) && ($config['formats']['pdf']['enabled'] ?? null) === true) {
            throw new LogicException('"nowo_report_kit.formats.pdf.enabled" is true but Twig is not available: register symfony/twig-bundle or set it to "auto".');
        }
        foreach (['nowo_report_kit.renderer.pdf', 'nowo_report_kit.template_check_warmer', 'nowo_report_kit.pdf_document_renderer', 'nowo_report_kit.font_cache_warmer', PdfDocumentRendererInterface::class] as $id) {
            if ($container->hasAlias($id)) {
                $container->removeAlias($id);
            } else {
                $container->removeDefinition($id);
            }
        }
        $formats = $container->getParameter('nowo_report_kit.formats');
        $formats = array_values(array_diff(is_array($formats) ? $formats : [], ['pdf']));
        $container->setParameter('nowo_report_kit.formats', $formats);
        $provider = $container->hasDefinition('nowo_report_kit.settings_provider') ? $container->getDefinition('nowo_report_kit.settings_provider') : null;
        if ($provider instanceof Definition) {
            $provider->replaceArgument(1, $formats);
        }
    }
}
