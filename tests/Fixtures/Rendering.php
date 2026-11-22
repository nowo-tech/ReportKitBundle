<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

use function dirname;
use function glob;
use function preg_match;

/**
 * Translator and Twig environment with the bundle's catalogues and templates, for renderer tests without a kernel.
 */
final class Rendering
{
    public static function translator(string $locale = 'en'): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (glob(dirname(__DIR__, 2) . '/src/Resources/translations/*.yaml') ?: [] as $file) {
            if (preg_match('/\.([a-z]{2})\.yaml$/', $file, $m) === 1) {
                $translator->addResource('yaml', $file, $m[1], 'NowoReportKitBundle');
            }
        }

        return $translator;
    }

    /**
     * @param array<string, string> $templates extra in-memory templates
     */
    public static function twig(array $templates = []): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__, 2) . '/src/Resources/views', 'NowoReportKit');
        $twig = new Environment(new ChainLoader([new ArrayLoader($templates), $files]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new TranslationExtension(self::translator()));

        return $twig;
    }
}
