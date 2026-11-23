<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use Nowo\ReportKitBundle\Application\Chart\ReportChartSvg;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Event\ReportExportedEvent;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Exception\FormatNotEnabledException;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\PreparedReport;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportBrandResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Nowo\ReportKitBundle\Presentation\Form\ReportFilterType;
use Nowo\ReportKitBundle\Presentation\View\ScreenSections;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\Table;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;

/**
 * The report pages (contract §7.1, §8): the list of the types the viewer may open, the report page (sections from
 * the PDF view model, filter form, Export menu) and the export routes. Every action goes through the
 * {@see ReportKitGuard} first; the list and the report page through the views rate limit, the exports through theirs,
 * before any build. Unknown type → 404, refused type → 403, rate limit → 429, a type over a limit → 503. HTML pages
 * are `no-store, private` like the files. Stateless.
 *
 * @internal
 */
final readonly class ReportController
{
    /** Route names. */
    public const ROUTE_INDEX  = 'nowo_report_kit_index';
    public const ROUTE_SHOW   = 'nowo_report_kit_show';
    public const ROUTE_EXPORT = 'nowo_report_kit_export';

    /** Bundle domain. */
    private const DOMAIN = NowoReportKitBundle::TRANSLATION_DOMAIN;

    /**
     * @param array{index: string, show: string, sections: string} $templates page templates (`web_ui.templates.*` or the bundle's)
     * @param string|null $layout `web_ui.layout_template`; null = UiKit's admin layout
     */
    public function __construct(
        private ReportKitGuard $guard,
        private ReportBuilderInterface $builder,
        private ReportExporterInterface $exporter,
        private RenderedFileResponder $responder,
        private ReportRateLimiter $limits,
        private ReportBrandResolverInterface $brands,
        private RenderLimits $renderLimits,
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private array $templates,
        private ?string $layout = null,
        private ?EventDispatcherInterface $events = null,
        private ?SubscriptionsInterface $subscriptions = null,
        private ?ReportTypeRegistryInterface $types = null,
    ) {
    }

    /**
     * `GET <prefix>`: the report types available to the viewer.
     */
    public function index(): Response
    {
        $viewer = $this->guard->viewer();
        $this->limits->view($viewer->owner);
        $rows = [];
        foreach ($this->builder->available($viewer->owner, $viewer->user) as $key => $type) {
            $domain = $type->translationDomain() ?? 'messages';
            $rows[] = [
                'key'         => $key,
                'title'       => $this->translator->trans($type->labelKey(), [], $domain),
                'description' => $this->translator->trans($type->descriptionKey(), [], $domain),
                'icon'        => $type->icon(),
                'href'        => $this->urls->generate(self::ROUTE_SHOW, ['type' => $key]),
            ];
        }

        return $this->render($this->templates['index'], [
            'subscriptions' => $this->subscriptionsTable($viewer),
            'dialog_id'     => SubscriptionController::DIALOG_ID,
            'page'          => new ListPage(
                new PageHeader(self::label('index.title'), self::label('index.intro')),
                [new Column('title', self::label('index.column.title'), required: true), new Column('description', self::label('index.column.description'))],
                $rows,
                rowActions: static fn (mixed $row): array => is_array($row) && is_string($row['href'] ?? null) ? [Action::view($row['href'], self::label('index.open'))] : [],
                empty: new EmptyState(null, self::label('index.empty')),
                bundle: self::DOMAIN,
                layout: $this->layout,
                attributes: ['data-testid' => 'report-kit-index'],
            ),
        ]);
    }

    /**
     * `GET <prefix>/{type}`: the report page.
     */
    public function show(Request $request, string $type): Response
    {
        $viewer = $this->guard->viewer();
        $this->limits->view($viewer->owner);
        $prepared = $this->prepare($type, $viewer, $request);
        $report   = $this->build($prepared);
        $query    = $this->query($prepared);
        $actions  = [];
        if ($this->subscriptions instanceof SubscriptionsInterface && !$viewer->user instanceof GuestViewer) {
            $href      = $this->urls->generate(SubscriptionController::ROUTE_SCHEDULE, ['type' => $type] + $query);
            $actions[] = new Action('send', self::label('schedule.open'), $href, icon: 'mail', attributes: [
                'data-testid'               => 'report-kit-schedule-open',
                'data-nowo-ui-dialog-form'  => true,
                'data-nowo-ui-dialog-title' => $this->translator->trans('schedule.title', [], self::DOMAIN),
            ], dialogId: SubscriptionController::DIALOG_ID);
        }
        foreach ($this->exporter->formats() as $format) {
            if ($format !== ReportFormat::Json) {
                $actions[] = new Action('download', self::label('export.' . $format->value), $this->urls->generate(self::ROUTE_EXPORT, ['type' => $type, 'format' => $format->value] + $query), icon: 'download', attributes: ['data-testid' => 'report-kit-export-' . $format->value]);
            }
        }

        return $this->render($this->templates['show'], [
            'page' => new ShowPage(
                new PageHeader($report->title, $report->summary, $actions, back: new BackLink($this->urls->generate(self::ROUTE_INDEX), self::label('show.back'), 'report-kit-back')),
                bundle: self::DOMAIN,
                layout: $this->layout,
                attributes: ['data-testid' => 'report-kit-show'],
            ),
            'report'            => $report,
            'sections'          => $this->sections($report),
            'filters'           => $this->filters($prepared, $query)->createView(),
            'filters_action'    => $this->urls->generate(self::ROUTE_SHOW, ['type' => $type]),
            'sections_template' => $this->templates['sections'],
            'context_fallback'  => $prepared->request->parameters->contextUnavailable,
            'dialog_id'         => $this->subscriptions instanceof SubscriptionsInterface ? SubscriptionController::DIALOG_ID : null,
        ]);
    }

    /**
     * `GET <prefix>/{type}/export.{format}`: the file (`csv`, `xlsx`, `pdf`; JSON is the API's), private and never
     * cached; {@see ReportExportedEvent} afterwards.
     */
    public function export(Request $request, string $type, string $format): StreamedResponse
    {
        $viewer = $this->guard->viewer();
        $file   = ReportFormat::tryFrom($format);
        if (!$file instanceof ReportFormat || $file === ReportFormat::Json || !in_array($file, $this->exporter->formats(), true)) {
            throw new NotFoundHttpException('Unknown export format.');
        }
        $this->limits->export($viewer->owner);
        $prepared = $this->prepare($type, $viewer, $request);
        $report   = $this->build($prepared);
        try {
            $rendered = $this->exporter->export($report, $file);
        } catch (FormatNotEnabledException $e) {
            throw new NotFoundHttpException('Unknown export format.', $e);
        } catch (ReportLimitExceededException $e) {
            throw new ServiceUnavailableHttpException(null, 'The report is too large to export now.', $e);
        }
        $response = $this->responder->respond($rendered);
        $rows     = 0;
        foreach ($report->sections as $section) {
            $rows += count($section->rows);
        }
        $this->events?->dispatch(new ReportExportedEvent($viewer->owner, $type, $file, $prepared->request->parameters->context, $rows, $rendered->size), ReportKitEvents::REPORT_EXPORTED);

        return $response;
    }

    /**
     * The viewer's subscriptions as a UiKit table; null when the subscriptions are off (or for the demo guest). Row
     * actions are links to the pause / resume / delete pages, opened in the one remote dialog of the page: no form
     * per row.
     */
    private function subscriptionsTable(Viewer $viewer): ?Table
    {
        if (!$this->subscriptions instanceof SubscriptionsInterface || $viewer->user instanceof GuestViewer) {
            return null;
        }
        $rows = [];
        foreach ($this->subscriptions->ofOwner($viewer->owner) as $view) {
            $rows[$view->id->value] = SubscriptionRows::row($view, $this->types?->get($view->type), $this->translator);
        }
        $dialog = $this->translator->trans('subscriptions.title', [], self::DOMAIN);

        return new Table(
            [
                new Column('report', self::label('subscriptions.column.report'), required: true),
                new Column('parameters', self::label('subscriptions.column.parameters')),
                new Column('schedule', self::label('subscriptions.column.schedule')),
                new Column('next_run', self::label('subscriptions.column.next_run')),
                new Column('status', self::label('subscriptions.column.status')),
            ],
            $rows,
            /** @param array{id: string, enabled: bool} $row */
            function (array $row) use ($dialog): array {
                $enabled = $row['enabled'];
                $attrs   = ['data-nowo-ui-dialog-form' => true, 'data-nowo-ui-dialog-title' => $dialog];

                return [
                    new Action($enabled ? 'pause' : 'resume', self::label($enabled ? 'subscriptions.pause.label' : 'subscriptions.resume.label'), $this->urls->generate(SubscriptionController::ROUTE_TOGGLE, ['id' => $row['id']]), attributes: $attrs + ['data-testid' => 'report-kit-subscription-toggle'], dialogId: SubscriptionController::DIALOG_ID),
                    new Action('delete', self::label('subscriptions.delete.label'), $this->urls->generate(SubscriptionController::ROUTE_DELETE, ['id' => $row['id']]), variant: 'danger', attributes: $attrs + ['data-testid' => 'report-kit-subscription-delete'], dialogId: SubscriptionController::DIALOG_ID),
                ];
            },
            caption: self::label('subscriptions.title'),
            bundle: self::DOMAIN,
            attributes: ['data-testid' => 'report-kit-subscriptions'],
        );
    }

    private static function label(string $key): Label
    {
        return new Label($key, self::DOMAIN);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        $response = new Response($this->twig->render($template, $context));
        // The page carries the same figures as the files (audit ZY-I3).
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The type's build; a type over one of its limits (or refusing its own arguments) answers 503, not 500 (audit ZY-L3).
     */
    private function build(PreparedReport $prepared): Report
    {
        try {
            return $prepared->type->build($prepared->request);
        } catch (ReportLimitExceededException|InvalidReportArgumentException $e) {
            throw new ServiceUnavailableHttpException(null, 'The report cannot be built now.', $e);
        }
    }

    private function prepare(string $type, Viewer $viewer, Request $request): PreparedReport
    {
        try {
            return $this->builder->prepare($type, $viewer->owner, $viewer->user, $request->query->all(), false, $request->getLocale());
        } catch (UnknownReportTypeException $e) {
            throw new NotFoundHttpException('Unknown report type.', $e);
        } catch (ReportAccessDeniedException $e) {
            throw new AccessDeniedHttpException('This report is not available.', $e);
        } catch (ReportLimitExceededException $e) {
            throw new ServiceUnavailableHttpException(null, 'The report covers too many contexts.', $e);
        }
    }

    /**
     * The resolved parameters as a query string (export links and the filter form), never the raw request.
     *
     * @return array<string, int|string>
     */
    private function query(PreparedReport $prepared): array
    {
        $parameters = $prepared->request->parameters;
        $query      = ['period' => $parameters->periodDays];
        if ($parameters->context instanceof ContextRef) {
            $query['context'] = $parameters->context->key();
        }
        foreach ($parameters->extra as $name => $value) {
            if ($value !== null) {
                $query[$name] = is_bool($value) ? ($value ? '1' : '0') : $value;
            }
        }

        return $query;
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function filters(PreparedReport $prepared, array $query): FormInterface
    {
        $schema  = $prepared->type->parameters();
        $periods = [];
        foreach ($schema->periods as $days) {
            $periods[$this->translator->trans('filter.period_days', ['%days%' => $days], self::DOMAIN)] = $days;
        }
        $contexts = [];
        foreach ($prepared->choices as $choice) {
            $contexts[$choice->label] = $choice->ref->key();
        }

        return $this->forms->createNamed('', ReportFilterType::class, $query, [
            'periods'          => $periods,
            'contexts'         => $contexts,
            'context_required' => $schema->contextRequired,
            'extra'            => $schema->extra,
            'type_domain'      => $prepared->type->translationDomain() ?? 'messages',
            'action'           => $this->urls->generate(self::ROUTE_SHOW, ['type' => $prepared->type->key()]),
        ]);
    }

    /**
     * The sections through the PDF view model ({@see ScreenSections}; same bounds: `limits.pdf_max_rows`, `limits.pdf_max_cell`).
     *
     * @return list<array<string, mixed>>
     */
    private function sections(Report $report): array
    {
        $accent = ReportChartSvg::colour($this->brands->brandFor($report->brandContext)->light->primary);

        return ScreenSections::build($report, $accent, $this->renderLimits->pdfMaxRows, $this->renderLimits->pdfMaxCell);
    }
}
