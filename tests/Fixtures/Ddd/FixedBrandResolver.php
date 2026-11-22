<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandColors;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;

/**
 * DDD-host brand resolver: a fixed brand per context id.
 */
final readonly class FixedBrandResolver implements ReportBrandResolverInterface
{
    public function brandFor(?ContextRef $context): Brand
    {
        $colors = new BrandColors('#2f9e44', '#2b8a3e', '#c92a2a', '#e67700', '#ffffff', '#212529', '#f8f9fa');

        return new Brand('Ledger Corp ' . ($context instanceof ContextRef ? $context->id : 'all'), $colors, $colors);
    }
}
