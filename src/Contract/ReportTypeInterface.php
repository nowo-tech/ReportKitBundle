<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * One report type (host SPI, contract §4.3), tagged `nowo_report_kit.type` (autoconfigured). `build()` runs only
 * bounded aggregate queries over `$request->accessible` (or the effective `$request->parameters->context`).
 * {@see AbstractReportType} gives the common helpers.
 */
interface ReportTypeInterface
{
    /** Service tag of the report types. */
    public const TAG = 'nowo_report_kit.type';

    /**
     * Route key and configuration key, `^[a-z][a-z0-9_]{0,31}$`, unique.
     */
    public function key(): string;

    /**
     * Translation key of the title (domain {@see self::translationDomain()}).
     */
    public function labelKey(): string;

    /**
     * Translation key of the one-line description (domain {@see self::translationDomain()}).
     */
    public function descriptionKey(): string;

    /**
     * Translation domain of the label and description keys; null = `messages`.
     */
    public function translationDomain(): ?string;

    /**
     * Icon name of the list card (UiKit icon set).
     */
    public function icon(): string;

    /**
     * What the type accepts.
     */
    public function parameters(): ParameterSchema;

    /**
     * Permission the host's access checker must grant (S2); null = any signed-in viewer.
     */
    public function permission(): ?string;

    /**
     * Builds the report for one request.
     */
    public function build(ReportRequest $request): Report;
}
