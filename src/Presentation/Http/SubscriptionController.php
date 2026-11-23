<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Http;

use DateTimeZone;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactoryInterface;
use Nowo\ReportKitBundle\Application\Subscription\SubscriptionMapper;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportAccessDeniedException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionChangedException;
use Nowo\ReportKitBundle\Contract\Exception\SubscriptionLimitException;
use Nowo\ReportKitBundle\Contract\Exception\UnknownReportTypeException;
use Nowo\ReportKitBundle\Contract\Frequency;
use Nowo\ReportKitBundle\Contract\OwnerDirectoryInterface;
use Nowo\ReportKitBundle\Contract\PreparedReport;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportSettingsProviderInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use Nowo\ReportKitBundle\Contract\ReportTypeRegistryInterface;
use Nowo\ReportKitBundle\Contract\Schedule;
use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Contract\SubscriptionView;
use Nowo\ReportKitBundle\NowoReportKitBundle;
use Nowo\ReportKitBundle\Presentation\Form\ScheduleType;
use Nowo\UiKitBundle\Dialog\RemoteDialog;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * The subscription pages (contract §7.1, §8): the *Schedule by e-mail* form and the pause / resume / delete
 * confirmations of the owner's subscriptions. Each URL serves the full page (no JavaScript, deep link) and, for a
 * UiKit remote dialog request (`X-Nowo-Dialog: 1`), the fragment the dialog loads on open: an invalid POST answers
 * 422 in place, a saved one `204` + `X-Nowo-Redirect` to the list (`RemoteDialogResponder`). The header only picks the
 * template: CSRF, the guard, the rate limit and the owner check run for both modes.
 *
 * - Schedule: the report parameters come from the query string and are resolved and access-checked again by the
 *   builder (a refused type is 403, a context the owner cannot read is a form error, never "all accessible").
 * - Pause / resume / delete: looked up by id **and** owner (another owner's id → 404), CSRF token id per subscription,
 *   the version the owner saw posted back (a row changed meanwhile → 409 `SUBSCRIPTION_CHANGED`).
 *
 * Every page is `no-store, private`. Stateless.
 *
 * @internal
 */
final readonly class SubscriptionController
{
    /** Route names. */
    public const ROUTE_SCHEDULE = 'nowo_report_kit_schedule';
    public const ROUTE_TOGGLE   = 'nowo_report_kit_subscription_toggle';
    public const ROUTE_DELETE   = 'nowo_report_kit_subscription_delete';

    /** Id of the one remote dialog shell of the list and report pages. */
    public const DIALOG_ID = 'report-kit-dialog';

    private const DOMAIN = NowoReportKitBundle::TRANSLATION_DOMAIN;

    /**
     * @param array{page: string, fragment: string} $templates `web_ui.templates.schedule_dialog` (fragment) and the page
     * @param list<string> $frequencies `subscriptions.frequencies`
     * @param int $defaultHour `subscriptions.default_hour`
     * @param bool $allowHour `subscriptions.allow_hour_choice`
     * @param string $defaultTimezone `schedule.default_timezone`
     */
    public function __construct(
        private ReportKitGuard $guard,
        private ReportRateLimiter $limits,
        private ReportBuilderInterface $builder,
        private SubscriptionsInterface $subscriptions,
        private ReportTypeRegistryInterface $types,
        private OwnerDirectoryInterface $directory,
        private ReportSettingsProviderInterface $settings,
        private FormFactoryInterface $forms,
        private CsrfOnlyFormFactoryInterface $csrfForms,
        private RemoteDialogResponder $dialog,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private array $templates,
        private array $frequencies = ['daily', 'weekly', 'monthly'],
        private int $defaultHour = 7,
        private bool $allowHour = true,
        private string $defaultTimezone = 'UTC',
        private ?string $layout = null,
    ) {
    }

    /**
     * `GET|POST <prefix>/{type}/schedule?<report parameters>`: the schedule form; POST creates the subscription.
     */
    public function schedule(Request $request, string $type): Response
    {
        $viewer = $this->viewer();
        $this->limits->view($viewer->owner);
        $prepared = $this->prepare($type, $viewer, $request);
        $query    = SubscriptionMapper::raw($prepared->request->parameters);
        $asked    = $request->query->get('context');
        if ($prepared->request->parameters->contextUnavailable && is_string($asked)) {
            // Keep the context that was asked for: the POST resolves it again and refuses it (never "all accessible").
            $query['context'] = $asked;
        }
        $extra    = $this->extraFormats();
        $timezone = $this->directory->timezone($viewer->owner);
        $form     = $this->forms->createNamed('schedule', ScheduleType::class, [
            'frequency' => in_array(Frequency::Weekly->value, $this->frequencies, true) ? Frequency::Weekly->value : $this->frequencies[0],
            'weekday'   => 1,
            'month_day' => 1,
            'hour'      => $this->defaultHour,
            'timezone'  => is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : $this->defaultTimezone,
            'formats'   => [],
        ], [
            'action'      => $this->urls->generate(self::ROUTE_SCHEDULE, ['type' => $type] + $query),
            'frequencies' => $this->frequencies,
            'formats'     => $extra,
            'allow_hour'  => $this->allowHour,
        ]);
        $form->handleRequest($request);
        $this->guardCsrf($form);
        if (!$form->isSubmitted() && $prepared->request->parameters->contextUnavailable) {
            $form->addError(new FormError($this->translator->trans('schedule.error.context', [], self::DOMAIN)));
        }
        if ($form->isSubmitted() && $form->isValid() && $this->created($form, $prepared, $viewer, $extra)) {
            return $this->dialog->redirectToRoute(ReportController::ROUTE_INDEX, [], Response::HTTP_SEE_OTHER, $request);
        }

        return $this->page($request, $form, new PageHeader(
            $this->label('schedule.title'),
            new Label('schedule.intro', self::DOMAIN, ['%report%' => $this->title($prepared)]),
            back: new BackLink($this->urls->generate(ReportController::ROUTE_SHOW, ['type' => $type] + $query), $this->label('show.back_to_report'), 'report-kit-schedule-back'),
        ), $this->label('schedule.submit'), $this->urls->generate(ReportController::ROUTE_SHOW, ['type' => $type] + $query), 'report-kit-schedule');
    }

    /**
     * `GET|POST <prefix>/subscriptions/{id}/toggle`: pause or resume (the opposite of the current state).
     */
    public function toggle(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $this->limits->view($viewer->owner);
        $view = $this->subscription($viewer, $id);
        $form = $this->actionForm(self::ROUTE_TOGGLE, $view);
        $form->handleRequest($request);
        $this->guardCsrf($form, true);
        $action = $view->enabled ? 'pause' : 'resume';
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // @igor-ignore - A value built in this call (form, response, e-mail), never a shared service.
                $this->subscriptions->setEnabled($viewer->owner, $view->id, !$view->enabled, $this->postedVersion($form));

                return $this->dialog->redirectToRoute(ReportController::ROUTE_INDEX, [], Response::HTTP_SEE_OTHER, $request);
            } catch (SubscriptionChangedException) {
                return $this->changed($request, $viewer, $view, self::ROUTE_TOGGLE);
            }
        }

        return $this->actionPage($request, $form, $view, $action);
    }

    /**
     * `GET|POST <prefix>/subscriptions/{id}/delete`: delete.
     */
    public function delete(Request $request, string $id): Response
    {
        $viewer = $this->viewer();
        $this->limits->view($viewer->owner);
        $view = $this->subscription($viewer, $id);
        $form = $this->actionForm(self::ROUTE_DELETE, $view);
        $form->handleRequest($request);
        $this->guardCsrf($form, true);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->subscriptions->delete($viewer->owner, $view->id, $this->postedVersion($form));

                return $this->dialog->redirectToRoute(ReportController::ROUTE_INDEX, [], Response::HTTP_SEE_OTHER, $request);
            } catch (SubscriptionChangedException) {
                return $this->changed($request, $viewer, $view, self::ROUTE_DELETE);
            }
        }

        return $this->actionPage($request, $form, $view, 'delete');
    }

    /**
     * The signed-in viewer; the shared demo guest (`allow_unauthenticated`) cannot own subscriptions.
     */
    private function viewer(): Viewer
    {
        $viewer = $this->guard->viewer();
        if ($viewer->user instanceof GuestViewer) {
            throw new AccessDeniedHttpException('Sign in to schedule reports.');
        }

        return $viewer;
    }

    private function label(string $key): Label
    {
        return new Label($key, self::DOMAIN);
    }

    /**
     * Creates the subscription from valid form data; false (with a form error) when it cannot be created.
     *
     * @param FormInterface<array<string, mixed>> $form
     * @param list<string> $extra
     */
    private function created(FormInterface $form, PreparedReport $prepared, Viewer $viewer, array $extra): bool
    {
        // A choice outside the lists (no validator in the host) arrives as null and is refused by Schedule below.
        if ($prepared->request->parameters->contextUnavailable) {
            $form->addError(new FormError($this->translator->trans('schedule.error.context', [], self::DOMAIN)));

            return false;
        }
        /** @var array<string, mixed> $data */
        $data    = $form->getData();
        $formats = [];
        foreach (is_array($data['formats'] ?? null) ? $data['formats'] : [] as $format) {
            $value = is_string($format) && in_array($format, $extra, true) ? ReportFormat::from($format) : null;
            if ($value instanceof ReportFormat) {
                $formats[] = $value;
            }
        }
        try {
            $frequency = Frequency::tryFrom(is_string($data['frequency'] ?? null) ? $data['frequency'] : '') ?? throw new InvalidReportArgumentException('frequency');
            $schedule  = new Schedule(
                $frequency,
                $this->int($data['weekday'] ?? null),
                $this->int($data['month_day'] ?? null),
                $this->allowHour ? $this->int($data['hour'] ?? null) : $this->defaultHour,
                is_string($data['timezone'] ?? null) ? $data['timezone'] : '',
            );
            $this->subscriptions->create($viewer->owner, $prepared->type->key(), $prepared->request->parameters, $schedule, $formats);
        } catch (SubscriptionLimitException $limit) {
            $form->addError(new FormError($this->translator->trans('schedule.error.limit', ['%limit%' => $limit->limit], self::DOMAIN)));

            return false;
            // Defensive: every field is a choice list the form already refuses (Symfony's transformation-failure
            // handling, with or without a validator); a host override of the form or of the settings between the GET
            // and the POST could still reach the catch below.
            // @codeCoverageIgnoreStart
        } catch (InvalidReportArgumentException|UnknownReportTypeException) {
            $form->addError(new FormError($this->translator->trans('schedule.error.invalid', [], self::DOMAIN)));

            return false;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : -1);
    }

    /**
     * The extra formats a subscription may add to PDF: XLSX and CSV when enabled.
     *
     * @return list<string>
     */
    private function extraFormats(): array
    {
        $extra = [];
        foreach ($this->settings->formatsEnabled() as $format) {
            if ($format === ReportFormat::Xlsx || $format === ReportFormat::Csv) {
                $extra[] = $format->value;
            }
        }

        return $extra;
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

    private function title(PreparedReport $prepared): string
    {
        return $this->translator->trans($prepared->type->labelKey(), [], $prepared->type->translationDomain() ?? 'messages');
    }

    /**
     * The translated title of a type key (the key itself when the type was removed).
     */
    private function typeTitle(string $key): string
    {
        $type = $this->types->get($key);

        return $type instanceof ReportTypeInterface ? $this->translator->trans($type->labelKey(), [], $type->translationDomain() ?? 'messages') : $key;
    }

    /**
     * The owner's subscription (the route requirement guarantees a well-formed id); 404 otherwise.
     */
    private function subscription(Viewer $viewer, string $id): SubscriptionView
    {
        return $this->subscriptions->get($viewer->owner, new SubscriptionId($id)) ?? throw new NotFoundHttpException('Unknown subscription.');
    }

    /**
     * A submitted form whose CSRF token is missing or wrong answers 403 (contract §14), never a 422 that invites a retry.
     *
     * @param FormInterface<mixed> $form
     * @param bool $csrfOnly a CSRF-only form: any invalid submission is a token failure
     */
    private function guardCsrf(FormInterface $form, bool $csrfOnly = false): void
    {
        if (!$form->isSubmitted()) {
            return;
        }
        if ($csrfOnly && !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        foreach ($form->getErrors() as $error) {
            if ($error instanceof FormError && $error->getCause() instanceof CsrfToken) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
        }
    }

    /**
     * CSRF (token id per subscription and action) + the version the owner saw.
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $route, SubscriptionView $view): FormInterface
    {
        return $this->csrfForms->createWithFields(
            $this->urls->generate($route, ['id' => $view->id->value]),
            $route . '_' . $view->id->value,
            ['version' => (string) $view->version],
        );
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function postedVersion(FormInterface $form): int
    {
        $version = $form->has('version') ? $form->get('version')->getData() : null;

        return is_numeric($version) ? (int) $version : -1;
    }

    /**
     * 409: the row changed meanwhile; the page shows the current state with a fresh form.
     */
    private function changed(Request $request, Viewer $viewer, SubscriptionView $stale, string $route): Response
    {
        $current = $this->subscription($viewer, $stale->id->value);
        $form    = $this->actionForm($route, $current);
        // @igor-ignore - A value built in this call (form, response, e-mail), never a shared service.
        $form->addError(new FormError($this->translator->trans('subscriptions.changed', [], self::DOMAIN)));
        $response = $this->actionPage($request, $form, $current, $route === self::ROUTE_DELETE ? 'delete' : ($current->enabled ? 'pause' : 'resume'));
        // @igor-ignore - A value built in this call (form, response, e-mail), never a shared service.
        $response->setStatusCode(Response::HTTP_CONFLICT);
        // @igor-ignore - A value built in this call (form, response, e-mail), never a shared service.
        $response->headers->set('X-Report-Kit-Code', SubscriptionChangedException::CODE);

        return $response;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function actionPage(Request $request, FormInterface $form, SubscriptionView $view, string $action): Response
    {
        return $this->page($request, $form, new PageHeader(
            $this->label('subscriptions.' . $action . '.title'),
            new Label('subscriptions.' . $action . '.message', self::DOMAIN, ['%report%' => $this->typeTitle($view->type)]),
            back: new BackLink($this->urls->generate(ReportController::ROUTE_INDEX), $this->label('show.back'), 'report-kit-subscription-back'),
        ), $this->label('subscriptions.' . $action . '.label'), $this->urls->generate(ReportController::ROUTE_INDEX), 'report-kit-subscription-' . $action, $action === 'delete');
    }

    /**
     * The page or, for a dialog request, the fragment; 422 when a submitted form is invalid.
     *
     * @param FormInterface<mixed> $form
     */
    private function page(Request $request, FormInterface $form, PageHeader $header, Label $submit, string $cancel, string $testId, bool $danger = false): Response
    {
        $page = new FormPage($header, $form->createView(), $submit, $cancel, bundle: self::DOMAIN, layout: $this->layout, attributes: ['data-testid' => $testId]);
        $html = $this->twig->render($this->dialog->template($this->templates['page'], $this->templates['fragment'], $request), [
            'page'   => $page,
            'danger' => $danger,
            'dialog' => $this->dialog->isDialog($request),
        ]);
        $response = new Response($html, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store, private');

        return RemoteDialog::vary($response, $this->dialog->headers());
    }
}
