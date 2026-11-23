<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportSection;
use Nowo\ReportKitBundle\Presentation\View\ScreenSections;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\Table;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;

use function dirname;

/**
 * REQ-UI-003 page contract of the report pages: the list and the report page extend UiKit scaffolds (list, show), no
 * <html>, CDN, inline handler or confirm(); framework classes only through theme slots; the stub theme is reflected;
 * no raw or missing translation keys (strict).
 */
final class PageContractTest extends PageContractTestCase
{
    private const DOMAIN = 'NowoReportKitBundle';

    public static function pageFixtures(): iterable
    {
        yield 'report list' => new PageFixture('@NowoReportKit/web/index.html.twig', ['page' => self::index([['title' => 'Orders', 'description' => 'Orders by status', 'href' => '/reports/orders']])]);
        yield 'report list (empty)' => new PageFixture('@NowoReportKit/web/index.html.twig', ['page' => self::index([])]);
        yield 'report page' => new PageFixture('@NowoReportKit/web/show.html.twig', self::show(true));
        yield 'report page (context fallback, no data)' => new PageFixture('@NowoReportKit/web/show.html.twig', self::show(false));
        yield 'report page with the schedule dialog (S3)' => new PageFixture('@NowoReportKit/web/show.html.twig', ['dialog_id' => 'report-kit-dialog'] + self::show(true));
        yield 'report list with subscriptions (S3)' => new PageFixture('@NowoReportKit/web/index.html.twig', ['page' => self::index([]), 'subscriptions' => new Table([new Column('report', self::label('subscriptions.column.report'))], [['report' => 'Orders']], caption: self::label('subscriptions.title'), bundle: self::DOMAIN), 'dialog_id' => 'report-kit-dialog']);
        yield 'report list with no subscription (S3)' => new PageFixture('@NowoReportKit/web/index.html.twig', ['page' => self::index([]), 'subscriptions' => new Table([new Column('report')], [], bundle: self::DOMAIN), 'dialog_id' => 'report-kit-dialog']);
        yield 'schedule page (S3)' => new PageFixture('@NowoReportKit/web/form.html.twig', self::schedule());
    }

    public function testReportPageRegions(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoReportKit/web/show.html.twig', self::show(true)))->html;

        foreach (['report-kit-meta', 'report-kit-filters', 'report-kit-section', 'report-kit-chart', 'report-kit-series-stats', 'report-kit-table', 'report-kit-glance'] as $testid) {
            self::assertStringContainsString('data-testid="' . $testid . '"', $html);
        }
        self::assertStringContainsString('data-nowo-ui-theme-only="dark"', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>bold</b>', $html);
        self::assertStringNotContainsString(' style="', $html, 'no inline style (CSP)');
    }

    protected static function templatePaths(): array
    {
        return ['NowoReportKit' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private static function index(array $rows): ListPage
    {
        return new ListPage(
            new PageHeader(self::label('index.title'), self::label('index.intro')),
            [new Column('title', self::label('index.column.title'), required: true), new Column('description', self::label('index.column.description'))],
            $rows,
            rowActions: static fn (mixed $row): array => [Action::view('/reports/orders', self::label('index.open'))],
            empty: new EmptyState(null, self::label('index.empty')),
            bundle: self::DOMAIN,
            attributes: ['data-testid' => 'report-kit-index'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function show(bool $data): array
    {
        $sections = $data ? [
            ReportSection::keyValues('Figures', 'Figure', 'Value', 'Change', [['label' => 'Orders', 'value' => 42, 'delta' => 12, 'tone' => 'good'], ['label' => 'New', 'value' => 1, 'delta' => null, 'tone' => 'good']]),
            ReportSection::table('Daily', ['Day', 'Paid'], [['2026-11-21', 3], ['2026-11-22', 5]], chart: ReportSection::CHART_SERIES),
            ReportSection::table('Status', ['Status', 'Orders'], [['Paid', 30], ['Open', 12]], chart: ReportSection::CHART_SLICES),
            ReportSection::table('Orders', ['Order', 'Note', 'Change (%)'], [['A-1', '<b>bold</b>', 5], ['A-2', null, -1.5]], truncatedAt: 2),
            ReportSection::table('Trace', ['#', 'Frame', 'File'], [[1, 'main', 'app.php']], style: ReportSection::STYLE_TRACE, highlight: [true]),
        ] : [ReportSection::table('Orders', ['Order'], [])];
        $report  = new Report('Orders by status', 'Last 30 days · All accessible contexts', new DateTimeImmutable('2026-11-23 09:00 UTC'), $sections, 'orders-all', 'Orders per status', $data ? [['label' => 'Healthy', 'tone' => 'good']] : [], $data ? '/orders' : null, $data ? '72 orders' : null);
        $filters = Forms::createFormFactory()->createNamedBuilder('', FormType::class, ['period' => 30], ['method' => 'GET', 'translation_domain' => self::DOMAIN])
            ->add('period', ChoiceType::class, ['label' => 'filter.period', 'choices' => ['30' => 30], 'choice_translation_domain' => false])
            ->getForm()
            ->createView();

        return [
            'page' => new ShowPage(
                new PageHeader($report->title, $report->summary, [new Action('download', self::label('export.csv'), '/reports/orders/export.csv', icon: 'download')], back: new BackLink('/reports', self::label('show.back'))),
                bundle: self::DOMAIN,
                attributes: ['data-testid' => 'report-kit-show'],
            ),
            'report'            => $report,
            'sections'          => ScreenSections::build($report, '#1c7ed6', 120, 180),
            'filters'           => $filters,
            'filters_action'    => '/reports/orders',
            'sections_template' => '@NowoReportKit/web/_sections.html.twig',
            'context_fallback'  => !$data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(): array
    {
        $form = Forms::createFormFactory()->createNamedBuilder('schedule', FormType::class, ['frequency' => 'daily'], ['translation_domain' => self::DOMAIN])
            ->add('frequency', ChoiceType::class, ['label' => 'schedule.frequency.label', 'choices' => ['schedule.frequency.daily' => 'daily']])
            ->getForm();
        $form->addError(new \Symfony\Component\Form\FormError('limit'));

        return [
            'page'   => new \Nowo\UiKitBundle\View\FormPage(new PageHeader(self::label('schedule.title'), self::label('schedule.title'), back: new BackLink('/reports/orders', self::label('show.back_to_report'))), $form->createView(), self::label('schedule.submit'), '/reports/orders', bundle: self::DOMAIN, attributes: ['data-testid' => 'report-kit-schedule']),
            'danger' => false,
            'dialog' => false,
        ];
    }

    private static function label(string $key): Label
    {
        return new Label($key, self::DOMAIN);
    }
}
