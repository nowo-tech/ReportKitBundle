<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * A report ready to build (S2): the type, the request with the resolved parameters and the narrowed contexts, and the
 * context choices the viewer may switch to (the filter form). Built by {@see ReportBuilderInterface::prepare()}.
 */
final readonly class PreparedReport
{
    /**
     * @param ReportTypeInterface $type the report type
     * @param ReportRequest $request what the type receives
     * @param list<ContextChoice> $choices the contexts of `$request->accessible`, with their labels, in order
     */
    public function __construct(public ReportTypeInterface $type, public ReportRequest $request, public array $choices)
    {
    }
}
