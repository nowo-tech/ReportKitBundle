<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;

/**
 * The race tests on the standard host.
 */
final class StandardHostConcurrencyTest extends ConcurrencyTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
