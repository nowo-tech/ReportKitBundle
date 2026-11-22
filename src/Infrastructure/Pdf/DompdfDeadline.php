<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Infrastructure\Pdf;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Psr\Clock\ClockInterface;

/**
 * The time budget inside Dompdf (audit ZG-M1): registered on the `begin_page_reflow` and `begin_page_render`
 * callbacks, it aborts the render with {@see ReportLimitExceededException} once the deadline has passed. Built per
 * render, never kept on a service. The phases before the first page (HTML parsing, style cascade) are bounded by
 * `limits.max_pdf_html_bytes`.
 *
 * @internal
 */
final readonly class DompdfDeadline
{
    /**
     * @param ClockInterface $clock the clock the deadline is read from
     * @param DateTimeImmutable $deadline when the render must stop
     * @param int $seconds the configured budget, for the exception
     */
    public function __construct(private ClockInterface $clock, private DateTimeImmutable $deadline, private int $seconds)
    {
    }

    /**
     * Throws when the deadline has passed (Dompdf passes the page frame, the canvas and the font metrics: unused).
     *
     * @throws ReportLimitExceededException
     */
    public function __invoke(): void
    {
        if ($this->clock->now() > $this->deadline) {
            throw new ReportLimitExceededException(ReportLimitExceededException::RENDER_SECONDS, $this->seconds);
        }
    }

    /**
     * The Dompdf callback list (`Dompdf::setCallbacks()`).
     *
     * @return list<array{event: string, f: self}>
     */
    public function callbacks(): array
    {
        return [['event' => 'begin_page_reflow', 'f' => $this], ['event' => 'begin_page_render', 'f' => $this]];
    }
}
