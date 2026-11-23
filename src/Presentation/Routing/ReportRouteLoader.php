<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Routing;

use Nowo\ReportKitBundle\Contract\SubscriptionId;
use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Nowo\ReportKitBundle\Presentation\Http\SubscriptionController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;

/**
 * Route loader `nowo_report_kit_ui` (contract §7.1): the report pages (and, with subscriptions on, the schedule and
 * subscription pages) under `web_ui.path_prefix`, only when
 * `web_ui.enabled` (no route otherwise). The host imports it once: `nowo_report_kit: {resource: ., type:
 * nowo_report_kit_ui}`. Route names `nowo_report_kit_*`; internal UI paths, not versioned (rule 5).
 */
final class ReportRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_report_kit_ui';

    /** Report type keys in the path. */
    public const TYPE_REQUIREMENT = '[a-z][a-z0-9_]{0,31}';

    /** Export formats in the path (JSON is the API's). */
    public const FORMAT_REQUIREMENT = 'csv|xlsx|pdf';

    /**
     * @param bool $enabled `web_ui.enabled`
     * @param string $path `web_ui.path_prefix`
     * @param string $controller service id of the {@see ReportController}
     * @param string|null $subscriptions service id of the {@see SubscriptionController}; null = no subscription routes
     */
    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $path = '/reports',
        private readonly string $controller = 'nowo_report_kit.web.controller',
        private readonly ?string $subscriptions = null,
    ) {
        parent::__construct();
    }

    /**
     * The routes (none while the web UI is off).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $base = rtrim($this->path, '/');
        $c    = $this->controller . '::';
        $routes->add(ReportController::ROUTE_INDEX, new Route($base, ['_controller' => $c . 'index'], methods: ['GET']));
        $routes->add(ReportController::ROUTE_SHOW, new Route($base . '/{type}', ['_controller' => $c . 'show'], ['type' => self::TYPE_REQUIREMENT], methods: ['GET']));
        $routes->add(ReportController::ROUTE_EXPORT, new Route($base . '/{type}/export.{format}', ['_controller' => $c . 'export'], ['type' => self::TYPE_REQUIREMENT, 'format' => self::FORMAT_REQUIREMENT], methods: ['GET']));
        if ($this->subscriptions !== null) {
            // GET serves the page / dialog fragment (lazy dialogs), POST does the work (CSRF).
            $s = $this->subscriptions . '::';
            $routes->add(SubscriptionController::ROUTE_SCHEDULE, new Route($base . '/{type}/schedule', ['_controller' => $s . 'schedule'], ['type' => self::TYPE_REQUIREMENT], methods: ['GET', 'POST']));
            $routes->add(SubscriptionController::ROUTE_TOGGLE, new Route($base . '/subscriptions/{id}/toggle', ['_controller' => $s . 'toggle'], ['id' => SubscriptionId::REQUIREMENT], methods: ['GET', 'POST']));
            $routes->add(SubscriptionController::ROUTE_DELETE, new Route($base . '/subscriptions/{id}/delete', ['_controller' => $s . 'delete'], ['id' => SubscriptionId::REQUIREMENT], methods: ['GET', 'POST']));
        }

        return $routes;
    }

    /**
     * Only the `nowo_report_kit_ui` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
