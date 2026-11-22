<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Ddd;

use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;

/**
 * DDD-host report type: implements the port directly, no bundle base class, no translator, tagged by hand.
 */
final readonly class PlainReportType implements ReportTypeInterface
{
    public function key(): string
    {
        return 'ledger';
    }

    public function labelKey(): string
    {
        return 'ledger.title';
    }

    public function descriptionKey(): string
    {
        return 'ledger.description';
    }

    public function translationDomain(): string
    {
        return 'ledger';
    }

    public function icon(): string
    {
        return 'book';
    }

    public function parameters(): ParameterSchema
    {
        return new ParameterSchema([7], 7, false);
    }

    public function permission(): string
    {
        return 'ledger.view';
    }

    public function build(ReportRequest $request): Report
    {
        return new Report('Ledger', 'Last 7 days', $request->now, [ReportSection::table('Entries', ['Owner'], [[$request->owner->id]])], 'ledger-' . $request->owner->id, locale: $request->locale);
    }
}
