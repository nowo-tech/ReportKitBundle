<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Presentation;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Application\Brand\DefaultReportBrandResolver;
use Nowo\ReportKitBundle\Application\Export\RenderLimits;
use Nowo\ReportKitBundle\Application\Owner\PropertyOwnerResolver;
use Nowo\ReportKitBundle\Contract\AbstractReportType;
use Nowo\ReportKitBundle\Contract\Exception\FormatNotEnabledException;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportLimitExceededException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\PreparedReport;
use Nowo\ReportKitBundle\Contract\RenderedFile;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportBuilderInterface;
use Nowo\ReportKitBundle\Contract\ReportExporterInterface;
use Nowo\ReportKitBundle\Contract\ReportFormat;
use Nowo\ReportKitBundle\Contract\ReportKitAccessCheckerInterface;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Infrastructure\Security\AllowAllReportKitAccessChecker;
use Nowo\ReportKitBundle\Presentation\Http\GuestViewer;
use Nowo\ReportKitBundle\Presentation\Http\RenderedFileResponder;
use Nowo\ReportKitBundle\Presentation\Http\ReportController;
use Nowo\ReportKitBundle\Presentation\Http\ReportKitGuard;
use Nowo\ReportKitBundle\Presentation\Http\ReportRateLimiter;
use Nowo\ReportKitBundle\Presentation\Routing\ReportRouteLoader;
use Nowo\ReportKitBundle\Tests\Fixtures\Web\PersonalReportType;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The page guard (sign-in, area checker, owner, demo guest), the route loader switch and the controller's mapping of
 * limits and late format refusals.
 */
final class PagesSupportTest extends TestCase
{
    public function testGuardRefusesAnonymousWrongAreaAndOwnerless(): void
    {
        $owners = new PropertyOwnerResolver('user', null, 'userIdentifier');
        $guest  = (new ReportKitGuard(new AllowAllReportKitAccessChecker(), $owners, null, true))->viewer();
        self::assertSame('guest:anonymous', $guest->owner->key());
        self::assertInstanceOf(GuestViewer::class, $guest->user);

        $refusals = [
            'anonymous' => new ReportKitGuard(new AllowAllReportKitAccessChecker(), $owners, new TokenStorage()),
            'area'      => new ReportKitGuard($this->area(false), $owners, $this->tokens('alice')),
            'owner'     => new ReportKitGuard($this->area(true), new PropertyOwnerResolver('user', stdClass::class, 'id'), $this->tokens('alice')),
        ];
        foreach ($refusals as $case => $guard) {
            try {
                $guard->viewer();
                self::fail($case);
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('user:alice', (new ReportKitGuard($this->area(true), $owners, $this->tokens('alice')))->viewer()->owner->key());
    }

    public function testRouteLoaderIsEmptyWhileTheUiIsOff(): void
    {
        $loader = new ReportRouteLoader();

        self::assertCount(0, $loader->load('.'));
        self::assertTrue($loader->supports('.', ReportRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'yaml'));
        self::assertSame(['nowo_report_kit_index', 'nowo_report_kit_show', 'nowo_report_kit_export'], array_keys((new ReportRouteLoader(true, '/r'))->load('.')->all()));
    }

    public function testControllerMapsLimitsAndLateFormatRefusals(): void
    {
        $cases = [
            'scope too large' => [new ReportLimitExceededException(ReportLimitExceededException::SCOPE_CONTEXTS, 5000), null, ServiceUnavailableHttpException::class],
            'render too long' => [null, new ReportLimitExceededException(ReportLimitExceededException::RENDER_SECONDS, 25), ServiceUnavailableHttpException::class],
            'format off now'  => [null, new FormatNotEnabledException(ReportFormat::Csv), NotFoundHttpException::class],
        ];
        foreach (['json', 'docx', 'xlsx'] as $format) {
            try {
                $this->controller(null, null)->export(new Request(), 'personal', $format);
                self::fail($format);
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ($cases as $case => [$prepareError, $exportError, $expected]) {
            try {
                $this->controller($prepareError, $exportError)->export(new Request(), 'personal', 'csv');
                self::fail($case);
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e, $case);
            }
        }
    }

    public function testATypeOverItsLimitsAnswers503ZyL3(): void
    {
        foreach ([new ReportLimitExceededException(ReportLimitExceededException::SCOPE_CONTEXTS, 5000), new InvalidReportArgumentException('bad')] as $error) {
            foreach (['show', 'export'] as $action) {
                try {
                    $controller = $this->controller(null, null, $error);
                    $action === 'show' ? $controller->show(new Request(), 'failing') : $controller->export(new Request(), 'failing', 'csv');
                    self::fail($action);
                } catch (ServiceUnavailableHttpException $e) {
                    self::assertSame($error, $e->getPrevious());
                }
            }
        }
    }

    private function area(bool $grant): ReportKitAccessCheckerInterface
    {
        $area = $this->createMock(ReportKitAccessCheckerInterface::class);
        $area->method('canAccess')->willReturn($grant);

        return $area;
    }

    private function tokens(string $user): TokenStorage
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        return $tokens;
    }

    private function controller(?Throwable $prepareError, ?Throwable $exportError, ?Throwable $buildError = null): ReportController
    {
        $translator = new Translator('en');
        $type       = $buildError instanceof Throwable ? new class($translator, $buildError) extends AbstractReportType {
            public function __construct(TranslatorInterface $translator, private readonly Throwable $error)
            {
                parent::__construct($translator);
            }

            public function key(): string
            {
                return 'failing';
            }

            public function build(ReportRequest $request): Report
            {
                throw $this->error;
            }
        } : new PersonalReportType($translator);
        $builder = $this->createMock(ReportBuilderInterface::class);
        $prepare = $builder->method('prepare');
        if ($prepareError instanceof Throwable) {
            $prepare->willThrowException($prepareError);
        } else {
            $prepare->willReturn(new PreparedReport($type, new ReportRequest(new OwnerRef('user', 'alice'), new stdClass(), new ReportParameters(30, null), [], 'en', 'UTC', new DateTimeImmutable('2026-11-23')), []));
        }
        $exporter = $this->createMock(ReportExporterInterface::class);
        $exporter->method('formats')->willReturn([ReportFormat::Csv]);
        $export = $exporter->method('export');
        if ($exportError instanceof Throwable) {
            $export->willThrowException($exportError);
        } else {
            $export->willReturn(new RenderedFile('x.csv', 'text/csv', RenderedFile::temporaryStream(), 0));
        }
        $limiter = static fn (string $id): RateLimiterFactory => new RateLimiterFactory(['id' => $id, 'policy' => 'no_limit'], new InMemoryStorage());

        return new ReportController(
            new ReportKitGuard(new AllowAllReportKitAccessChecker(), new PropertyOwnerResolver('user', null, 'userIdentifier'), $this->tokens('alice')),
            $builder,
            $exporter,
            new RenderedFileResponder(),
            new ReportRateLimiter($limiter('v'), $limiter('e')),
            new DefaultReportBrandResolver(),
            new RenderLimits(),
            new Environment(new ArrayLoader()),
            Forms::createFormFactory(),
            $this->createMock(UrlGeneratorInterface::class),
            $translator,
            ['index' => 'i', 'show' => 's', 'sections' => 'x'],
        );
    }
}
