<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Application\Export;

use Nowo\ReportKitBundle\Contract\Exception\FormatNotEnabledException;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Psr\Clock\ClockInterface;
use Throwable;

use function fclose;
use function fstat;
use function in_array;

/**
 * Default {@see ReportExporterInterface}: picks the renderer of the format, checks the format against the settings
 * provider (per call), gives the render its time budget and names the file.
 */
final readonly class ReportExporter implements ReportExporterInterface
{
    /** @var array<string, ReportRendererInterface> */
    private array $renderers;

    /**
     * @param iterable<ReportRendererInterface> $renderers the renderers of the installed formats
     * @param ReportSettingsProviderInterface $settings which formats are on right now
     * @param ClockInterface $clock clock of the render budget
     * @param RenderLimits $limits the `limits.*`
     * @param string $filenamePrefix `filename_prefix`
     */
    public function __construct(
        iterable $renderers,
        private ReportSettingsProviderInterface $settings,
        private ClockInterface $clock,
        private RenderLimits $limits,
        private string $filenamePrefix = 'report',
    ) {
        $byFormat = [];
        foreach ($renderers as $renderer) {
            $byFormat[$renderer->format()->value] = $renderer;
        }
        $this->renderers = $byFormat;
    }

    /**
     * The file in $format.
     */
    public function export(Report $report, ReportFormat $format): RenderedFile
    {
        $renderer = $this->renderers[$format->value] ?? null;
        if ($renderer === null || !in_array($format, $this->settings->formatsEnabled(), true)) {
            throw new FormatNotEnabledException($format);
        }
        $stream = RenderedFile::temporaryStream();
        try {
            $renderer->render($report, new RenderBudget($this->clock, $this->limits->renderSeconds), $stream);
        } catch (Throwable $e) {
            fclose($stream);

            throw $e;
        }
        $stat = fstat($stream);

        return new RenderedFile(ReportFileName::build($this->filenamePrefix, $report->filename, $format), $format->contentType(), $stream, $stat === false ? 0 : $stat['size']);
    }

    /**
     * @return list<ReportFormat>
     */
    public function formats(): array
    {
        $formats = [];
        foreach ($this->settings->formatsEnabled() as $format) {
            if (isset($this->renderers[$format->value])) {
                $formats[] = $format;
            }
        }

        return $formats;
    }
}
