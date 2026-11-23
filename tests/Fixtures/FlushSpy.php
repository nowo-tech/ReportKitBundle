<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

/**
 * Counts `onFlush` events of every entity manager: the bundle never flushes one (REQ-ARCH-001 rule 6).
 */
final class FlushSpy
{
    public int $flushes = 0;

    public function onFlush(): void
    {
        ++$this->flushes;
    }
}
