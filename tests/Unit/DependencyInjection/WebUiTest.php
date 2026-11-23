<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ReportKitBundle\Contract\OwnerResolverInterface;
use Nowo\ReportKitBundle\Contract\ReportAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportScopeResolverInterface;
use Nowo\ReportKitBundle\DependencyInjection\Compiler\WebUiRequirementsPass;
use Nowo\ReportKitBundle\DependencyInjection\NowoReportKitExtension;
use Nowo\ReportKitBundle\Infrastructure\Security\AllowAllReportKitAccessChecker;
use Nowo\ReportKitBundle\Infrastructure\Security\ConfigurableReportKitAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Twig\Environment;

/**
 * S2 wiring: host SPIs by service id, the closed defaults, the pages only with `web_ui.enabled`, and REQ-UI-002
 * rule 4 (no SecurityBundle → no build unless the demo-only flag).
 */
final class WebUiTest extends TestCase
{
    public function testHostServicesReplaceTheDefaults(): void
    {
        $container = $this->load([
            'owner'  => ['resolver' => 'app.owner'],
            'scope'  => ['resolver' => 'app.scope', 'access_checker' => 'app.access'],
            'web_ui' => ['enabled' => true, 'security' => ['access_checker' => 'app.area'], 'templates' => ['show' => '@App/show.html.twig']],
        ]);

        self::assertSame('app.owner', (string) $container->getAlias('nowo_report_kit.owner_resolver'));
        self::assertSame('app.scope', (string) $container->getAlias('nowo_report_kit.scope_resolver'));
        self::assertSame('app.access', (string) $container->getAlias('nowo_report_kit.access_checker'));
        self::assertSame('app.area', (string) $container->getAlias('nowo_report_kit.web.access_checker'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.permission_checker_warmer'), 'a checker is configured');
        $templates = $container->getDefinition('nowo_report_kit.web.controller')->getArgument(11);
        self::assertSame(['index' => '@NowoReportKit/web/index.html.twig', 'show' => '@App/show.html.twig', 'sections' => '@NowoReportKit/web/_sections.html.twig'], $templates);
        foreach ([OwnerResolverInterface::class, ReportScopeResolverInterface::class, ReportAccessCheckerInterface::class, ReportKitAccessCheckerInterface::class] as $alias) {
            self::assertTrue($container->hasAlias($alias));
        }
    }

    public function testDefaultsAreClosedAndThePagesAreOptIn(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasDefinition('nowo_report_kit.permission_checker_warmer'));
        self::assertTrue($container->hasDefinition('nowo_report_kit.builder'));
        self::assertFalse($container->hasDefinition('nowo_report_kit.web.controller'));
        self::assertFalse($container->getDefinition('nowo_report_kit.web.route_loader')->getArgument(0), 'routes importable, none while off');

        $web = $this->load(['web_ui' => ['enabled' => true]]);
        self::assertSame(ConfigurableReportKitAccessChecker::class, $web->getDefinition('nowo_report_kit.web.access_checker')->getClass());
        self::assertSame(['ROLE_USER'], $web->getDefinition('nowo_report_kit.web.access_checker')->getArgument(1));
    }

    public function testRequirementsPass(): void
    {
        $pass = new WebUiRequirementsPass();
        $pass->process(new ContainerBuilder()); // pages off: nothing to check

        $missing = [
            'no UiKit'    => [[], false, false],
            'no Twig'     => [['NowoUiKitBundle' => 1, 'NowoFormKitBundle' => 1], false, false],
            'no Security' => [['NowoUiKitBundle' => 1, 'NowoFormKitBundle' => 1], true, false],
        ];
        foreach ($missing as $case => [$bundles, $twig, $allow]) {
            try {
                $pass->process($this->pages($bundles, $twig, $allow));
                self::fail($case);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $demo = $this->pages(['NowoUiKitBundle' => 1, 'NowoFormKitBundle' => 1], true, true);
        $pass->process($demo);
        self::assertSame(AllowAllReportKitAccessChecker::class, $demo->getDefinition('nowo_report_kit.web.access_checker')->getClass());

        $custom = $this->pages(['NowoUiKitBundle' => 1, 'NowoFormKitBundle' => 1], true, true, 'app.area');
        $pass->process($custom);
        self::assertSame('app.area', (string) $custom->getAlias('nowo_report_kit.web.access_checker'));

        $secured = $this->pages(['NowoUiKitBundle' => 1, 'NowoFormKitBundle' => 1], true, false);
        $secured->register('security.token_storage', stdClass::class);
        $secured->register('security.authorization_checker', stdClass::class);
        $pass->process($secured);
        self::assertSame(ConfigurableReportKitAccessChecker::class, $secured->getDefinition('nowo_report_kit.web.access_checker')->getClass());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoReportKitExtension())->load([['enabled' => true] + $config], $container);

        return $container;
    }

    /**
     * @param array<string, int> $bundles
     */
    private function pages(array $bundles, bool $twig, bool $allow, ?string $area = null): ContainerBuilder
    {
        $container = $this->load(['web_ui' => ['enabled' => true, 'security' => ['allow_unauthenticated' => $allow, 'access_checker' => $area]]]);
        $container->setParameter('kernel.bundles', $bundles);
        if ($twig) {
            $container->register(Environment::class, stdClass::class);
            $container->register('form.factory', stdClass::class);
        }

        return $container;
    }
}
