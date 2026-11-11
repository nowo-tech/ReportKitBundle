<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit;

use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    public function testTheExtensionAliasIsTheConfigurationRootAndIsBuiltOnce(): void
    {
        $bundle    = new NowoReportKitBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoReportKitExtension::class, $extension);
        self::assertSame('nowo_report_kit', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
