<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function class_exists;
use function sprintf;

/**
 * Loads `nowo_report_kit`: validates the strict configuration tree and publishes it as container parameters.
 *
 * S0 registers no service, route, listener or authenticator, whatever `enabled` says. The parameters exist so that
 * the host's `security.yaml` patterns can already follow `%nowo_report_kit.api_prefix%` (one-value prefix rule).
 */
final class NowoReportKitExtension extends Extension
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_report_kit';

    /**
     * Suggested format engines (Q-O3): `true` without the engine refuses to build; `auto` follows the install.
     *
     * @var array<string, array{string, string}>
     */
    public const FORMAT_ENGINES = [
        'xlsx' => ['OpenSpout\\Writer\\XLSX\\Writer', 'openspout/openspout'],
        'pdf'  => ['Dompdf\\Dompdf', 'dompdf/dompdf'],
    ];

    /**
     * The extension alias, `nowo_report_kit`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Processes the configuration and sets the `nowo_report_kit.*` container parameters.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach (self::FORMAT_ENGINES as $format => [$class, $package]) {
            // The engines are suggested packages: PHPStan only sees the dev install, where they are absent.
            if ($config['formats'][$format]['enabled'] === true && !class_exists($class)) { // @phpstan-ignore function.impossibleType
                throw new InvalidConfigurationException(sprintf('"nowo_report_kit.formats.%s.enabled" is true but %s is not installed: run "composer require %s" or set it to "auto" (Q-O3).', $format, $class, $package));
            }
        }
        $container->setParameter(self::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(self::ALIAS . '.api_prefix', $config['api']['prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.config', $config);
    }
}
