<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Fixtures;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportParametersResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Presentation\Http\RenderedFileResponder;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host controller of the integration and worker tests: owner A may read `project:a1` and `project:a2`, owner B only
 * `project:b1`; everything comes from the current request.
 */
final readonly class ExportController
{
    /** Contexts per owner. */
    public const ACCESSIBLE = ['a' => ['a1', 'a2'], 'b' => ['b1']];

    public function __construct(
        private ReportTypeRegistryInterface $types,
        private ReportParametersResolverInterface $parameters,
        private ReportExporterInterface $exporter,
        private RenderedFileResponder $responder,
    ) {
    }

    public function __invoke(Request $request, string $type, string $owner, string $format): Response
    {
        $reportType = $this->types->get($type);
        if (!$reportType instanceof ReportTypeInterface) {
            return new Response('', 404);
        }
        $accessible = [];
        foreach (self::ACCESSIBLE[$owner] ?? [] as $id) {
            $accessible[] = new ContextRef('project', $id);
        }
        $parameters = $this->parameters->resolve($reportType->parameters(), $request->query->all(), $accessible);
        $report     = $reportType->build(new ReportRequest(
            new OwnerRef('user', $owner),
            new stdClass(),
            $parameters,
            $accessible,
            (string) $request->query->get('locale', 'en'),
            'UTC',
            new DateTimeImmutable('2026-11-22T10:00:00+00:00'),
        ));

        return $this->responder->respond($this->exporter->export($report, ReportFormat::from($format)));
    }
}
