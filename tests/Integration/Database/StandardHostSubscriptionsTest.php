<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Database;

use Nowo\ReportKitBundle\Tests\Integration\Kernel\Host;

/**
 * The S3 functional suite on a standard host (attribute `User`, prepended XML mapping, Doctrine owner directory).
 */
final class StandardHostSubscriptionsTest extends SubscriptionFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
