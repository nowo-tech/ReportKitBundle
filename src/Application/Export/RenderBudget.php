<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Export;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Psr\Clock\ClockInterface;

/**
 * The time budget of one render (`limits.render_seconds`), created per call and checked between sections. Never kept
 * on a service.
 *
 * @internal
 */
final readonly class RenderBudget
{
    private DateTimeImmutable $deadline;

    /**
     * @param ClockInterface $clock the clock the deadline is read from
     * @param int $seconds the budget
     */
    public function __construct(private ClockInterface $clock, private int $seconds)
    {
        $this->deadline = $clock->now()->modify('+' . $seconds . ' seconds');
    }

    /**
     * When the budget runs out (handed to the PDF runner, which checks it inside Dompdf).
     */
    public function deadline(): DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * @throws ReportLimitExceededException when the budget is spent
     */
    public function check(): void
    {
        if ($this->clock->now() > $this->deadline) {
            throw new ReportLimitExceededException(ReportLimitExceededException::RENDER_SECONDS, $this->seconds);
        }
    }
}
