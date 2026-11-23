<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Routing;

use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;

/**
 * Route loader `nowo_report_kit_ui` (contract §7.1): the report pages under `web_ui.path_prefix`, only when
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
     */
    public function __construct(private readonly bool $enabled = false, private readonly string $path = '/reports', private readonly string $controller = 'nowo_report_kit.web.controller')
    {
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
