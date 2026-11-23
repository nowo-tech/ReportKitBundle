<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

use const DATE_ATOM;

final class ReportRequestTest extends TestCase
{
    public function testTheWindowIsTheLastNUtcDays(): void
    {
        $request = $this->request(new ReportParameters(3, null));
        $window  = $request->window();

        self::assertSame('2026-11-20T00:00:00+00:00', $window->from->format(DATE_ATOM));
        self::assertSame('2026-11-23T00:00:00+00:00', $window->to->format(DATE_ATOM));
        self::assertSame('2026-11-17T00:00:00+00:00', $window->previous->format(DATE_ATOM));
        self::assertSame('2026-11-22T00:00:00+00:00', $window->today->format(DATE_ATOM));
        self::assertSame(['2026-11-20', '2026-11-21', '2026-11-22'], $window->days);
    }

    public function testTheContextMustBeAccessible(): void
    {
        $a = new ContextRef('project', 'a');
        self::assertSame([$a], $this->request(new ReportParameters(7, $a), [$a])->accessible);

        $this->expectException(InvalidReportArgumentException::class);
        $this->request(new ReportParameters(7, new ContextRef('project', 'A')), [$a]);
    }

    public function testTheContextLabelIsCleanedZgL5(): void
    {
        $request = new ReportRequest(new OwnerRef('user', '1'), new stdClass(), new ReportParameters(7, null), [], 'en', 'UTC', new DateTimeImmutable(), false, "Acme\tWeb\n");
        self::assertSame('Acme Web', $request->contextLabel);
        self::assertNull((new ReportRequest(new OwnerRef('user', '1'), new stdClass(), new ReportParameters(7, null), [], 'en', 'UTC', new DateTimeImmutable()))->contextLabel);
    }

    public function testContextFreeGrantNeedsAnEmptyScopeZyM1(): void
    {
        $owner = new OwnerRef('user', '1');
        self::assertFalse((new ReportRequest($owner, new stdClass(), new ReportParameters(7, null), [], 'en', 'UTC', new DateTimeImmutable()))->contextFreeGrant);
        self::assertTrue((new ReportRequest($owner, new stdClass(), new ReportParameters(7, null), [], 'en', 'UTC', new DateTimeImmutable(), contextFreeGrant: true))->contextFreeGrant);

        $this->expectException(InvalidReportArgumentException::class);
        new ReportRequest($owner, new stdClass(), new ReportParameters(7, null), [new ContextRef('project', 'a')], 'en', 'UTC', new DateTimeImmutable(), contextFreeGrant: true);
    }

    /**
     * @param list<mixed> $accessible
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $accessible, string $locale, string $timezone, ?string $label): void
    {
        $this->expectException(InvalidReportArgumentException::class);
        new ReportRequest(new OwnerRef('user', '1'), new stdClass(), new ReportParameters(7, null), $accessible, $locale, $timezone, new DateTimeImmutable(), false, $label); // @phpstan-ignore argument.type
    }

    /**
     * @return iterable<string, array{list<mixed>, string, string, ?string}>
     */
    public static function invalid(): iterable
    {
        yield 'accessible not refs' => [['project:a'], 'en', 'UTC', null];
        yield 'locale' => [[], 'english', 'UTC', null];
        yield 'timezone' => [[], 'en', 'Mars/Base', null];
        yield 'empty label' => [[], 'en', 'UTC', ''];
        yield 'long label' => [[], 'en', 'UTC', str_repeat('x', 201)];
        yield 'label only control characters' => [[], 'en', 'UTC', "\t\n"];
    }

    /**
     * @param list<ContextRef> $accessible
     */
    private function request(ReportParameters $parameters, array $accessible = []): ReportRequest
    {
        return new ReportRequest(new OwnerRef('user', '1'), new stdClass(), $parameters, $accessible, 'pt_BR', 'Europe/Madrid', new DateTimeImmutable('2026-11-22T23:30:00+00:00'));
    }
}
