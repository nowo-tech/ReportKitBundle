<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use Nowo\Brand\Contract\Brand;

/**
 * The brand a report file carries (host SPI, contract §4.3): logo, name and colours per context. The default uses
 * brand-contracts' scoped provider when the host has one, else its provider, else a neutral built-in brand.
 */
interface ReportBrandResolverInterface
{
    /**
     * The brand of `$context` (the report's effective context); null = the instance brand.
     */
    public function brandFor(?ContextRef $context): Brand;
}
