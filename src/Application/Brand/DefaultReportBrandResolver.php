<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Brand;

use InvalidArgumentException;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandColors;
use Nowo\Brand\Contract\BrandLookup;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\Brand\Contract\BrandScope;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;

/**
 * Default {@see ReportBrandResolverInterface} (contract §4.3): brand-contracts' `getBrandFor()` for the report's
 * context when the host's provider is scoped (brand per project, spec 220), its `getBrand()` otherwise, and a neutral
 * built-in brand without a provider. Resolved per call, nothing memoised.
 *
 * The scope is the report's effective context id (already checked against the viewer's accessible contexts); an id
 * that is not a valid brand scope (non-ASCII, spaces) uses the instance brand.
 */
final readonly class DefaultReportBrandResolver implements ReportBrandResolverInterface
{
    /** Name of the neutral brand. */
    public const NEUTRAL_NAME = 'Reports';

    /**
     * @param BrandProviderInterface|null $provider the host's brand provider, if any
     */
    public function __construct(private ?BrandProviderInterface $provider = null)
    {
    }

    /**
     * The brand of $context, or the instance brand.
     */
    public function brandFor(?ContextRef $context): Brand
    {
        if (!$this->provider instanceof BrandProviderInterface) {
            return self::neutral();
        }
        $scope = null;
        if ($context instanceof ContextRef) {
            try {
                $scope = new BrandScope($context->id);
            } catch (InvalidArgumentException) {
                $scope = null;
            }
        }

        return BrandLookup::brandFor($this->provider, $scope);
    }

    /**
     * The neutral built-in brand: grey and blue, no image.
     */
    public static function neutral(): Brand
    {
        $light = new BrandColors('#1c7ed6', '#1864ab', '#c92a2a', '#e67700', '#ffffff', '#212529', '#f8f9fa');
        $dark  = new BrandColors('#4dabf7', '#74c0fc', '#ff8787', '#ffa94d', '#1a1b1e', '#e9ecef', '#25262b');

        return new Brand(self::NEUTRAL_NAME, $light, $dark, isDefault: true);
    }
}
