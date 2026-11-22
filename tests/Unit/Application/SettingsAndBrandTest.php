<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandColors;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\Brand\Contract\BrandScope;
use Nowo\Brand\Contract\ScopedBrandProviderInterface;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\ConfigReportSettingsProvider;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use PHPUnit\Framework\TestCase;

final class SettingsAndBrandTest extends TestCase
{
    public function testTheConfigProviderReturnsTheCompiledValues(): void
    {
        $provider = new ConfigReportSettingsProvider(true, ['csv', 'pdf'], false);

        self::assertTrue($provider->enabled());
        self::assertSame([ReportFormat::Csv, ReportFormat::Pdf], $provider->formatsEnabled());
        self::assertFalse($provider->schedulesEnabled());
    }

    public function testWithoutAProviderTheBrandIsNeutral(): void
    {
        $brand = (new DefaultReportBrandResolver())->brandFor(new ContextRef('project', '1'));

        self::assertSame(DefaultReportBrandResolver::NEUTRAL_NAME, $brand->name);
        self::assertTrue($brand->isDefault);
        self::assertSame('#1c7ed6', $brand->light->primary);
    }

    public function testAPlainProviderGivesTheInstanceBrand(): void
    {
        $provider = new class implements BrandProviderInterface {
            public function getBrand(): Brand
            {
                return SettingsAndBrandTest::brand('Instance');
            }
        };

        self::assertSame('Instance', (new DefaultReportBrandResolver($provider))->brandFor(new ContextRef('project', '1'))->name);
    }

    public function testAScopedProviderGivesTheContextBrand(): void
    {
        $provider = new class implements ScopedBrandProviderInterface {
            public function getBrand(): Brand
            {
                return SettingsAndBrandTest::brand('Instance');
            }

            public function getBrandFor(BrandScope $scope): Brand
            {
                return SettingsAndBrandTest::brand('Project ' . $scope->id);
            }
        };
        $resolver = new DefaultReportBrandResolver($provider);

        self::assertSame('Project 42', $resolver->brandFor(new ContextRef('project', '42'))->name);
        self::assertSame('Instance', $resolver->brandFor(null)->name);
        // Not a valid brand scope (non-ASCII): the instance brand, never a guess.
        self::assertSame('Instance', $resolver->brandFor(new ContextRef('project', 'café'))->name);
        // ZG-L4: a scope is an id only, so another context type with the same id never takes the project's brand.
        self::assertSame('Instance', $resolver->brandFor(new ContextRef('team', '42'))->name);
        $teams = new DefaultReportBrandResolver($provider, 'team');
        self::assertSame('Project 42', $teams->brandFor(new ContextRef('team', '42'))->name);
        self::assertSame('Instance', $teams->brandFor(new ContextRef('project', '42'))->name);
        self::assertSame('Project 42', (new DefaultReportBrandResolver($provider, null))->brandFor(new ContextRef('board', '42'))->name);
    }

    public static function brand(string $name): Brand
    {
        $colors = new BrandColors('#112233', '#112233', '#112233', '#112233', '#ffffff', '#000000', '#ffffff');

        return new Brand($name, $colors, $colors);
    }
}
