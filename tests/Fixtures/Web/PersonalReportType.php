<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures\Web;

use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportSection;

/**
 * A type without context and without permission: the viewer's own figures.
 */
final class PersonalReportType extends AbstractReportType
{
    public function key(): string
    {
        return 'personal';
    }

    public function parameters(): ParameterSchema
    {
        return new ParameterSchema(context: false);
    }

    public function build(ReportRequest $request): Report
    {
        return $this->report($request, [ReportSection::table('Me', ['Owner'], [[$request->owner->key()]])]);
    }
}
