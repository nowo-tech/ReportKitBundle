<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Presentation\Routing;

use Nowo\ReportKitBundle\Application\Run\ReportLinksInterface;
use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

use function preg_match;
use function rtrim;

/**
 * Default {@see ReportLinksInterface} (contract §6.3, REQ-AUTH-011): absolute URLs of the report pages built from a
 * fixed base, never from the current request: `links.base_url`, else the router's `default_uri`
 * (`router.request_context.*` parameters). A fresh generator per call over the router's compiled routes, so the shared
 * request context (which a web request rewrites with its own Host) is never read or changed. Stateless.
 */
final readonly class RouterReportLinks implements ReportLinksInterface
{
    private string $base;

    /**
     * @param bool $pages `web_ui.enabled`: no links without the pages
     * @param string|null $baseUrl `links.base_url`
     * @param string $contextBaseUrl `%router.request_context.base_url%` (holds `default_uri` when it is set)
     * @param string $host `%router.request_context.host%`
     * @param string $scheme `%router.request_context.scheme%`
     */
    public function __construct(
        private RouterInterface $router,
        private bool $pages,
        ?string $baseUrl = null,
        string $contextBaseUrl = '',
        string $host = 'localhost',
        string $scheme = 'http',
    ) {
        $this->base = $baseUrl ?? (preg_match('#^https?://#i', $contextBaseUrl) === 1 ? rtrim($contextBaseUrl, '/') : $scheme . '://' . $host . rtrim($contextBaseUrl, '/'));
    }

    /**
     * {@inheritDoc}
     */
    public function reportUrl(string $type, array $query): ?string
    {
        return $this->pages ? $this->generator()->generate(ReportController::ROUTE_SHOW, ['type' => $type] + $query, UrlGeneratorInterface::ABSOLUTE_URL) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function subscriptionsUrl(): ?string
    {
        return $this->pages ? $this->generator()->generate(ReportController::ROUTE_INDEX, [], UrlGeneratorInterface::ABSOLUTE_URL) : null;
    }

    private function generator(): UrlGenerator
    {
        return new UrlGenerator($this->router->getRouteCollection(), RequestContext::fromUri($this->base));
    }
}
