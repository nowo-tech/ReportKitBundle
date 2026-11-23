<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

/**
 * Records every event it is attached to, in order.
 */
final class RecordingListener
{
    /** @var list<object> */
    public array $events = [];

    public function onEvent(object $event): void
    {
        $this->events[] = $event;
    }
}
