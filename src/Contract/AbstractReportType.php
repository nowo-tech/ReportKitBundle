<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

use function round;
use function str_replace;

/**
 * Optional base of a report type (Beacon's helpers): the window, the filters line and file name from the effective
 * scope, translated texts in the request locale. Nothing forces a host to extend it ({@see ReportTypeInterface} is
 * the contract).
 *
 * The filters line and the file name describe the **effective** scope: when the requested context was not
 * accessible they say "all accessible contexts" (audit ZE-I3), never the requested one.
 */
abstract class AbstractReportType implements ReportTypeInterface
{
    /** Translation domain of the bundle's own texts. */
    private const BUNDLE_DOMAIN = 'NowoReportKitBundle';

    /**
     * @param TranslatorInterface $translator translates the type's texts (its domain) and the bundle's (filters line)
     */
    public function __construct(protected readonly TranslatorInterface $translator)
    {
    }

    /**
     * `report_type.<key>.title`.
     */
    public function labelKey(): string
    {
        return 'report_type.' . $this->key() . '.title';
    }

    /**
     * `report_type.<key>.description`.
     */
    public function descriptionKey(): string
    {
        return 'report_type.' . $this->key() . '.description';
    }

    /**
     * The `messages` domain.
     */
    public function translationDomain(): ?string
    {
        return null;
    }

    /**
     * A generic chart icon.
     */
    public function icon(): string
    {
        return 'chart';
    }

    /**
     * 7, 30 or 90 days (default 30), optional context, no extras.
     */
    public function parameters(): ParameterSchema
    {
        return new ParameterSchema();
    }

    /**
     * No permission: any signed-in viewer.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * The UTC day window of the request.
     */
    protected function window(ReportRequest $request): ReportWindow
    {
        return $request->window();
    }

    /**
     * A report with the translated title, the filters line, the build time and the file name base
     * `<type>-<context-slug|all>-<Y-m-d>` (the exporter adds the prefix and the extension).
     *
     * @param list<ReportSection> $sections
     * @param list<array{label: string, tone: string}> $badges
     */
    protected function report(ReportRequest $request, array $sections, ?string $summary = null, array $badges = [], ?string $link = null, ?string $glance = null): Report
    {
        $context = $request->parameters->context;
        $scope   = $context instanceof ContextRef ? $request->contextLabel ?? $context->id : ('all');

        return new Report(
            $this->t($request, $this->labelKey()),
            $this->filtersLine($request),
            $request->now,
            $sections,
            str_replace('_', '-', $this->key()) . '-' . $scope . '-' . $request->now->setTimezone(new DateTimeZone($request->timezone))->format('Y-m-d'),
            $summary,
            $badges,
            $link,
            $glance,
            $context,
            $request->locale,
        );
    }

    /**
     * The period and the effective scope: the context's label (or id), "all accessible contexts", or "all accessible
     * contexts (the requested one is not available)".
     */
    protected function filtersLine(ReportRequest $request): string
    {
        $parameters = $request->parameters;
        $scope      = match (true) {
            $parameters->context instanceof ContextRef => $request->contextLabel ?? $parameters->context->id,
            $parameters->contextUnavailable            => $this->bundleText($request, 'report.scope.all_unavailable'),
            default                                    => $this->bundleText($request, 'report.scope.all'),
        };
        if (!$this->parameters()->context) {
            return $this->bundleText($request, 'report.filters.period', ['%count%' => $parameters->periodDays]);
        }

        return $this->bundleText($request, 'report.filters.period_scope', ['%count%' => $parameters->periodDays, '%scope%' => $scope]);
    }

    /**
     * A text of the type's own domain, in the request locale.
     *
     * @param array<string, float|int|string> $parameters
     */
    protected function t(ReportRequest $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, $this->translationDomain(), $request->locale);
    }

    /**
     * Mean hours, one decimal (null without samples).
     */
    protected static function hours(int $seconds, int $count): ?float
    {
        return $count > 0 ? round($seconds / $count / 3600, 1) : null;
    }

    /**
     * @param array<string, float|int|string> $parameters
     */
    private function bundleText(ReportRequest $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::BUNDLE_DOMAIN, $request->locale);
    }
}
