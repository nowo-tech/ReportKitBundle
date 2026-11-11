<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;

/**
 * Q-O3: Dompdf and OpenSpout are suggested; a format forced on without its engine refuses to build.
 */
final class FormatEnginesTest extends TestCase
{
    #[DataProvider('engines')]
    public function testAFormatForcedOnWithoutItsEngineRefusesToBuild(string $format, string $class): void
    {
        if (class_exists($class)) {
            self::markTestSkipped($class . ' is installed.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"nowo_report_kit.formats.' . $format . '.enabled" is true but ' . $class . ' is not installed');

        (new NowoReportKitExtension())->load([['formats' => [$format => ['enabled' => true]]]], new ContainerBuilder());
    }

    #[DataProvider('engines')]
    public function testAutoAndFalseNeverRequireTheEngine(string $format): void
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['formats' => [$format => ['enabled' => 'auto']]], ['formats' => [$format => ['enabled' => false]]]], $container);

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
