<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Integration\Web;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ReportKitBundle\Contract\Event\ReportKitEvents;
use Nowo\ReportKitBundle\Contract\Event\ReportSubscriptionChangedEvent;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\SubscriptionsInterface;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\WebKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function html_entity_decode;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function preg_match_all;

/**
 * The subscription pages (contract §7.1, §8, §14 "Access"): the *Schedule by e-mail* action and its lazy remote
 * dialog (page / fragment, 422 in place, 204 + X-Nowo-Redirect), the list with pause / resume / delete links (no form
 * per row), CSRF (403), the cap, the context check, other owners (404), stale versions (409) and the guest refusal.
 */
final class SubscriptionPagesTest extends WebKernelTestCase
{
    /** @var array<string, string> cookies of the current test */
    private array $cookies = [];

    private WebKernel $kernel;

    protected function setUp(): void
    {
        (new Filesystem())->mkdir(WebKernel::baseDir());
        $this->kernel = $this->bootTeam(['subscriptions' => ['max_per_owner' => 2]], true, 'sqlite:///' . WebKernel::baseDir() . '/pages.sqlite');
        $em           = $this->doctrine($this->kernel)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public function testTheReportPageOffersTheLazyScheduleDialog(): void
    {
        [$status, , $html] = $this->send('GET', '/reports/orders?period=30&context=project:a1', 'alice');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#data-testid="report-kit-schedule-open"[^>]*#', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-src="/reports/orders/schedule?period=30&amp;context=project:a1&amp;seed=0"', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-form', $html);
        self::assertSame(1, preg_match_all('#<dialog[^>]*id="report-kit-dialog"#', $html), 'one empty shell');
        self::assertStringNotContainsString('name="schedule[', $html, 'the form is not in the page: loaded on open');
    }

    public function testScheduleInTheDialogThenListPauseResumeAndDelete(): void
    {
        // Load the fragment, as the dialog does.
        [$status, $headers, $fragment] = $this->send('GET', '/reports/orders/schedule?period=30&context=project:a1', 'alice', [], true);
        self::assertSame(200, $status);
        self::assertStringNotContainsString('<html', $fragment);
        self::assertStringContainsString('name="schedule[frequency]"', $fragment);
        self::assertStringContainsString('data-nowo-ui-dialog-close', $fragment);
        self::assertStringContainsString('X-Nowo-Dialog', $headers['vary']);
        self::assertStringContainsString('no-store', $headers['cache-control']);

        // 422 in place: an hour outside the choices.
        [$status, , $invalid] = $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment, ['schedule[hour]' => '99']), true);
        self::assertSame(422, $status);
        self::assertStringContainsString('name="schedule[frequency]"', $invalid);

        // Saved: 204 + X-Nowo-Redirect to the list.
        [$status, $headers] = $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment, ['schedule[frequency]' => 'monthly', 'schedule[month_day]' => '28', 'schedule[formats][]' => 'csv']), true);
        self::assertSame(204, $status);
        self::assertSame('/reports', $headers['x-nowo-redirect']);

        $views = $this->subscriptions()->ofOwner(new OwnerRef('user', 'alice'));
        self::assertCount(1, $views);
        self::assertSame('monthly', $views[0]->schedule->frequency->value);
        self::assertSame(28, $views[0]->schedule->monthDay);
        self::assertSame('project:a1', $views[0]->parameters->context?->key());
        self::assertSame(['pdf', 'csv'], array_map(static fn (ReportFormat $f): string => $f->value, $views[0]->formats));
        $id = $views[0]->id->value;

        // The list: links that open the one dialog, no form per row.
        [$status, , $list] = $this->send('GET', '/reports', 'alice');
        self::assertSame(200, $status);
        self::assertStringContainsString('data-testid="report-kit-subscriptions"', $list);
        self::assertStringContainsString('href="/reports/subscriptions/' . $id . '/toggle"', $list);
        self::assertStringContainsString('href="/reports/subscriptions/' . $id . '/delete"', $list);
        self::assertSame(0, preg_match_all('#<form[^>]*subscriptions/#', $list));

        // bob cannot see, pause or delete it.
        foreach (['GET', 'POST'] as $method) {
            self::assertSame(404, $this->send($method, '/reports/subscriptions/' . $id . '/toggle', 'bob')[0]);
            self::assertSame(404, $this->send($method, '/reports/subscriptions/' . $id . '/delete', 'bob')[0]);
        }

        // Pause (full page, no JavaScript): 303 to the list.
        [, , $page] = $this->send('GET', '/reports/subscriptions/' . $id . '/toggle', 'alice');
        self::assertStringContainsString('<html', $page);
        [$status, $headers] = $this->send('POST', '/reports/subscriptions/' . $id . '/toggle', 'alice', $this->actionData($page));
        self::assertSame(303, $status);
        self::assertSame('/reports', $headers['location']);
        self::assertFalse($this->subscriptions()->ofOwner(new OwnerRef('user', 'alice'))[0]->enabled);

        // A second tab posts the old version: 409, nothing changed, a fresh form.
        [$status, $headers, $conflict] = $this->send('POST', '/reports/subscriptions/' . $id . '/toggle', 'alice', $this->actionData($page), true);
        self::assertSame(409, $status);
        self::assertSame('SUBSCRIPTION_CHANGED', $headers['x-report-kit-code']);
        self::assertStringContainsString('data-testid="report-kit-form-errors"', $conflict);
        self::assertFalse($this->subscriptions()->ofOwner(new OwnerRef('user', 'alice'))[0]->enabled);

        // Resume through the dialog, then delete.
        [, , $oldDelete] = $this->send('GET', '/reports/subscriptions/' . $id . '/delete', 'alice', [], true);
        self::assertSame(403, $this->send('POST', '/reports/subscriptions/' . $id . '/delete', 'alice', $this->actionData($page), true)[0], 'the toggle token cannot delete');
        [, , $resume] = $this->send('GET', '/reports/subscriptions/' . $id . '/toggle', 'alice', [], true);
        self::assertSame(204, $this->send('POST', '/reports/subscriptions/' . $id . '/toggle', 'alice', $this->actionData($resume), true)[0]);
        self::assertTrue($this->subscriptions()->ofOwner(new OwnerRef('user', 'alice'))[0]->enabled);
        [, , $delete] = $this->send('GET', '/reports/subscriptions/' . $id . '/delete', 'alice', [], true);
        self::assertStringContainsString('autofocus', $delete, 'the safe action has the focus');
        [$status, , $stale] = $this->send('POST', '/reports/subscriptions/' . $id . '/delete', 'alice', $this->actionData($oldDelete), true);
        self::assertSame(409, $status, 'the delete form of an older version');
        self::assertStringContainsString('data-testid="report-kit-subscription-delete"', $stale);
        self::assertSame(204, $this->send('POST', '/reports/subscriptions/' . $id . '/delete', 'alice', $this->actionData($delete), true)[0]);
        self::assertSame([], $this->subscriptions()->ofOwner(new OwnerRef('user', 'alice')));
        [, , $list] = $this->send('GET', '/reports', 'alice');
        self::assertStringContainsString('data-testid="report-kit-subscriptions-empty"', $list);
    }

    public function testCsrfIsRequired(): void
    {
        [, , $fragment]             = $this->send('GET', '/reports/orders/schedule?period=30', 'alice', [], true);
        $data                       = $this->scheduleData($fragment);
        $data['schedule']['_token'] = 'forged';
        self::assertSame(403, $this->send('POST', $this->action($fragment), 'alice', $data, true)[0]);
        unset($data['schedule']['_token']);
        self::assertSame(403, $this->send('POST', $this->action($fragment), 'alice', $data)[0]);
        self::assertSame([], $this->subscriptions()->ofOwner(new OwnerRef('user', 'alice')));
    }

    public function testTheCapAndAnUnreadableContextAreFormErrors(): void
    {
        foreach ([1, 2] as $i) {
            [, , $fragment] = $this->send('GET', '/reports/orders/schedule?period=30', 'alice', [], true);
            self::assertSame(204, $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment), true)[0]);
        }
        [, , $fragment]    = $this->send('GET', '/reports/orders/schedule?period=30', 'alice', [], true);
        [$status, , $html] = $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment), true);
        self::assertSame(422, $status);
        self::assertStringContainsString('You already have 2 scheduled reports', $html);

        // A context alice cannot read: the page would fall back to "all accessible"; a subscription refuses.
        [, , $fragment] = $this->send('GET', '/reports/orders/schedule?period=30&context=project:b1', 'bob', [], true);
        self::assertSame(204, $this->send('POST', $this->action($fragment), 'bob', $this->scheduleData($fragment), true)[0]);
        [, , $fragment]    = $this->send('GET', '/reports/orders/schedule?period=30&context=project:b1', 'alice', [], true);
        [$status, , $html] = $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment), true);
        self::assertSame(422, $status);
    }

    public function testRefusedTypesAndUnknownTypes(): void
    {
        // First, on the fresh kernel: this test drives http_kernel directly, without the services resetter.
        self::assertSame(401, $this->send('GET', '/reports/orders/schedule', null)[0]);
        self::assertSame(404, $this->send('GET', '/reports/nope/schedule', 'alice')[0]);
        self::assertSame(403, $this->send('GET', '/reports/orders/schedule', 'newbie')[0], 'zero contexts fail closed (ZY-M1)');
    }

    public function testEventsAreDispatchedForTheOwnersChanges(): void
    {
        $seen       = [];
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ReportKitEvents::SUBSCRIPTION_CHANGED, static function (ReportSubscriptionChangedEvent $event) use (&$seen): void {
            $seen[] = $event->change;
        });
        [, , $fragment] = $this->send('GET', '/reports/personal/schedule', 'alice', [], true);
        self::assertSame(204, $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment), true)[0]);
        self::assertSame(['created'], $seen);
    }

    public function testWithoutAValidatorAChoiceOutsideTheListsIsStillRefused(): void
    {
        $this->kernel = $this->bootWeb(['scope' => ['resolver' => \Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver::class, 'access_checker' => \Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker::class]], static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $c): void {
            $c->extension('framework', ['validation' => ['enabled' => false]]);
            $s = $c->services();
            $s->set(\Nowo\ReportKitBundle\Tests\Fixtures\OrdersReportType::class)->autowire()->autoconfigure();
            $s->set(\Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamScopeResolver::class);
            $s->set(\Nowo\ReportKitBundle\Tests\Fixtures\Web\TeamAccessChecker::class);
        }, true, [], 'sqlite:///' . WebKernel::baseDir() . '/novalidator.sqlite');
        $em = $this->doctrine($this->kernel)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        [, , $fragment] = $this->send('GET', '/reports/orders/schedule?period=30', 'alice', [], true);
        [$status, , $html] = $this->send('POST', $this->action($fragment), 'alice', $this->scheduleData($fragment, ['schedule[hour]' => '99', 'schedule[timezone]' => 'Mars/Olympus']), true);
        self::assertSame(422, $status);
        self::assertStringContainsString('name="schedule[hour]"', $html);
        self::assertSame([], $this->subscriptions()->ofOwner(new \Nowo\ReportKitBundle\Contract\OwnerRef('user', 'alice')));
    }

    private function subscriptions(): SubscriptionsInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $subscriptions = $container->get(SubscriptionsInterface::class);
        self::assertInstanceOf(SubscriptionsInterface::class, $subscriptions);

        return $subscriptions;
    }

    /**
     * @param array<string, string> $data
     *
     * @return array{int, array<string, string>, string}
     */
    private function send(string $method, string $uri, ?string $user, array $data = [], bool $dialog = false): array
    {
        $server = $user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw'];
        if ($dialog) {
            $server['HTTP_X_NOWO_DIALOG']    = '1';
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $request  = Request::create($uri, $method, $data, $this->cookies, [], $server);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }
        ob_start();
        $response->sendContent();
        $body    = (string) ob_get_clean();
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }
        $this->kernel->terminate($request, $response);

        return [$response->getStatusCode(), $headers, $body];
    }

    private function action(string $html): string
    {
        self::assertSame(1, preg_match('#<form[^>]*action="([^"]+)"#', $html, $match));

        return html_entity_decode($match[1]);
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, mixed>
     */
    private function scheduleData(string $html, array $override = []): array
    {
        self::assertSame(1, preg_match('#name="schedule\[_token\]"[^>]*value="([^"]+)"#', $html, $token));
        $flat   = $override + ['schedule[frequency]' => 'weekly', 'schedule[weekday]' => '1', 'schedule[month_day]' => '1', 'schedule[hour]' => '7', 'schedule[timezone]' => 'Europe/Madrid', 'schedule[_token]' => $token[1]];
        $fields = [];
        $lists  = [];
        foreach ($flat as $key => $value) {
            self::assertSame(1, preg_match('#^schedule\[([a-z_]+)\](\[\])?$#', $key, $m));
            if (isset($m[2])) {
                $lists[$m[1]][] = $value;
            } else {
                $fields[$m[1]] = $value;
            }
        }

        return ['schedule' => $fields + $lists];
    }

    /**
     * @return array<string, string>
     */
    private function actionData(string $html): array
    {
        self::assertSame(1, preg_match('#name="_token"[^>]*value="([^"]+)"#', $html, $token));
        self::assertSame(1, preg_match('#name="version"[^>]*value="(\d+)"#', $html, $version));

        return ['_token' => $token[1], 'version' => $version[1]];
    }
}
