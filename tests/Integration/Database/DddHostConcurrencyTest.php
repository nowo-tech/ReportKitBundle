<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;

/**
 * The race tests on the ddd host.
 */
final class DddHostConcurrencyTest extends ConcurrencyTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
