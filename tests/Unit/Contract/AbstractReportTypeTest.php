<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The optional base: titles in the request locale, and a filters line and file name that describe the effective scope
 * (audit ZE-I3).
 */
final class AbstractReportTypeTest extends TestCase
{
    public function testDefaults(): void
    {
        $type = $this->type(true);
        self::assertSame('report_type.sales_mix.title', $type->labelKey());
        self::assertSame('report_type.sales_mix.description', $type->descriptionKey());
        self::assertNull($type->translationDomain());
        self::assertSame('chart', $type->icon());
        self::assertNull($type->permission());
        self::assertEquals(new ParameterSchema(), (new class(new Translator('en')) extends AbstractReportType {
            public function key(): string
            {
                return 'x';
            }

            public function build(ReportRequest $request): Report
            {
                return $this->report($request, []);
            }
        })->parameters());
    }

    public function testAPickedContextNamesItInTheLineAndTheFileName(): void
    {
        $acme   = new ContextRef('project', 'acme-1');
        $report = $this->type(true)->build($this->request(new ReportParameters(30, $acme), [$acme], 'Acme Web'));

        self::assertSame('Ventas', $report->title);
        self::assertSame('Últimos 30 días · Acme Web', $report->filters);
        self::assertSame('sales-mix-Acme Web-2026-11-23', $report->filename);
        self::assertSame($acme, $report->brandContext);
        self::assertSame('es', $report->locale);

        $byId = $this->type(true)->build($this->request(new ReportParameters(30, $acme), [$acme]));
        self::assertSame('Últimos 30 días · acme-1', $byId->filters);
        self::assertSame('sales-mix-acme-1-2026-11-23', $byId->filename);
    }

    public function testNoContextMeansAllAccessible(): void
    {
        $report = $this->type(true)->build($this->request(new ReportParameters(7, null), [new ContextRef('project', 'a')]));

        self::assertSame('Últimos 7 días · Todos los contextos accesibles', $report->filters);
        self::assertSame('sales-mix-all-2026-11-23', $report->filename);
        self::assertNull($report->brandContext);
    }

    public function testAnUnavailableRequestedContextIsNeverNamedZeI3(): void
    {
        $report = $this->type(true)->build($this->request(new ReportParameters(7, null, [], true), [new ContextRef('project', 'a')]));

        self::assertSame('Últimos 7 días · Todos los contextos accesibles (el contexto solicitado no está disponible)', $report->filters);
        self::assertSame('sales-mix-all-2026-11-23', $report->filename);
    }

    public function testATypeWithoutContextShowsThePeriodOnly(): void
    {
        self::assertSame('Últimos 7 días', $this->type(false)->build($this->request(new ReportParameters(7, null), []))->filters);
    }

    private function type(bool $context): AbstractReportType
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['report_type.sales_mix.title' => 'Ventas'], 'es');
        $translator->addResource('array', [
            'report.filters.period_scope'  => 'Últimos %count% días · %scope%',
            'report.filters.period'        => 'Últimos %count% días',
            'report.scope.all'             => 'Todos los contextos accesibles',
            'report.scope.all_unavailable' => 'Todos los contextos accesibles (el contexto solicitado no está disponible)',
        ], 'es', 'NowoReportKitBundle');

        return new class($translator, $context) extends AbstractReportType {
            public function __construct(Translator $translator, private readonly bool $context)
            {
                parent::__construct($translator);
            }

            public function key(): string
            {
                return 'sales_mix';
            }

            public function parameters(): ParameterSchema
            {
                return new ParameterSchema(context: $this->context);
            }

            public function build(ReportRequest $request): Report
            {
                $this->assertSameHours();

                return $this->report($request, [], 'summary', [], '/r', $this->window($request)->days[0]);
            }

            private function assertSameHours(): void
            {
                TestCase::assertSame(1.5, self::hours(10800, 2));
                TestCase::assertNull(self::hours(0, 0));
            }
        };
    }

    /**
     * @param list<ContextRef> $accessible
     */
    private function request(ReportParameters $parameters, array $accessible, ?string $label = null): ReportRequest
    {
        // 23:30 UTC is already the 23rd in Madrid: the file name uses the owner's day.
        return new ReportRequest(new OwnerRef('user', '1'), new stdClass(), $parameters, $accessible, 'es', 'Europe/Madrid', new DateTimeImmutable('2026-11-22T23:30:00+00:00'), false, $label);
    }
}
