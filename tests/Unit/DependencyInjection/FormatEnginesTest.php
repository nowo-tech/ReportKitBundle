<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Q-O3: Dompdf and OpenSpout are suggested; a format forced on without its engine refuses to build.
 */
final class FormatEnginesTest extends TestCase
{
    #[DataProvider('engines')]
    public function testAFormatForcedOnWithoutItsEngineRefusesToBuild(string $format, string $class): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"nowo_report_kit.formats.' . $format . '.enabled" is true but ' . $class . ' is not installed');

        (new NowoReportKitExtension(static fn (string $engine): bool => $engine !== $class))->load([['formats' => [$format => ['enabled' => true]]]], new ContainerBuilder());
    }

    #[DataProvider('engines')]
    public function testAFormatForcedOnWithItsEngineIsOn(string $format): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['enabled' => true, 'formats' => [$format => ['enabled' => true]]]], $container);

        self::assertTrue($container->hasDefinition('nowo_report_kit.renderer.' . $format));
    }

    #[DataProvider('engines')]
    public function testAutoAndFalseNeverRequireTheEngine(string $format): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension(static fn (string $engine): bool => false))->load([['formats' => [$format => ['enabled' => 'auto']]], ['formats' => [$format => ['enabled' => false]]]], $container);

        self::assertFalse($container->getParameter('nowo_report_kit.enabled'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function engines(): iterable
    {
        foreach (NowoReportKitExtension::FORMAT_ENGINES as $format => [$class]) {
            yield $format => [$format, $class];
        }
    }
}
