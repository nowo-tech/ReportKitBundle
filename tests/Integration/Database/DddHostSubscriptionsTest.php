<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;

/**
 * The S3 functional suite on a DDD host (XML `Member` on a second entity manager, bundle XML registered by hand,
 * prefix `ddd_`, own owner resolver and directory).
 */
final class DddHostSubscriptionsTest extends SubscriptionFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
