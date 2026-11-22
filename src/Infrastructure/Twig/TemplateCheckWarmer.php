<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Twig;

use LogicException;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Twig\Environment;

use function sprintf;

/**
 * Audit ZE-I4: the configured template names of the features that are on must exist. Runs at `cache:warmup` (and on
 * the first kernel boot in debug), so a typo in `formats.pdf.template` is a build error, not a 500 on the first
 * export.
 *
 * @internal
 */
final readonly class TemplateCheckWarmer implements CacheWarmerInterface
{
    /**
     * @param Environment $twig its loader is asked whether each template exists
     * @param array<string, string> $templates configuration path => template name
     */
    public function __construct(private Environment $twig, private array $templates)
    {
    }

    /**
     * Not optional: a missing template stops the warmup.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @throws LogicException when a configured template does not exist
     *
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->templates as $path => $name) {
            if (!$this->twig->getLoader()->exists($name)) {
                throw new LogicException(sprintf('"nowo_report_kit.%s" names the template "%s", which does not exist.', $path, $name));
            }
        }

        return [];
    }
}
