<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use Nowo\ReportKitBundle\Application\ReportParametersResolver;
use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\ParameterDefinition;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\ParameterType;
use PHPUnit\Framework\TestCase;

final class ReportParametersResolverTest extends TestCase
{
    private ParameterSchema $schema;

    /** @var list<ContextRef> */
    private array $accessible;

    protected function setUp(): void
    {
        $this->schema     = new ParameterSchema([7, 30, 90], 30, true, false, ['top' => new ParameterDefinition(ParameterType::Int, 'top', 10, min: 1, max: 50)]);
        $this->accessible = [new ContextRef('project', 'a1'), new ContextRef('project', 'A2')];
    }

    public function testValidRawValuesAreKept(): void
    {
        $parameters = (new ReportParametersResolver())->resolve($this->schema, ['period' => '90', 'context' => 'project:A2', 'top' => '20', 'other' => 'x'], $this->accessible);

        self::assertSame(90, $parameters->periodDays);
        self::assertSame($this->accessible[1], $parameters->context);
        self::assertSame(['top' => 20], $parameters->extra);
        self::assertFalse($parameters->contextUnavailable);
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $resolver = new ReportParametersResolver();
        self::assertSame(30, $resolver->resolve($this->schema, ['period' => '45'], $this->accessible)->periodDays);
        self::assertSame(30, $resolver->resolve($this->schema, ['period' => '7.0'], $this->accessible)->periodDays);
        self::assertSame(7, $resolver->resolve($this->schema, ['period' => 7], $this->accessible)->periodDays);
        self::assertSame(['top' => 10], $resolver->resolve($this->schema, ['top' => '500'], $this->accessible)->extra);
        $none = $resolver->resolve($this->schema, [], $this->accessible);
        self::assertNull($none->context);
        self::assertFalse($none->contextUnavailable);
    }

    /**
     * Beacon FR-012 / audit ZE-I3: an unknown, unreadable or look-alike context falls back to every accessible context
     * and is flagged, so the report never claims to be about it; the same answer for all three cases.
     */
    public function testAnInaccessibleContextFallsBackToAllAndIsFlagged(): void
    {
        $resolver = new ReportParametersResolver();
        foreach (['project:b1', 'project:a2', 'project:A1', 'nonsense', ['project:a1']] as $requested) {
            $parameters = $resolver->resolve($this->schema, ['context' => $requested], $this->accessible);
            self::assertNull($parameters->context);
            self::assertTrue($parameters->contextUnavailable);
        }
        self::assertFalse($resolver->resolve($this->schema, ['context' => ''], $this->accessible)->contextUnavailable);
    }

    public function testATypeWithoutContextIgnoresIt(): void
    {
        $parameters = (new ReportParametersResolver())->resolve(new ParameterSchema(context: false), ['context' => 'project:a1'], $this->accessible);

        self::assertNull($parameters->context);
        self::assertFalse($parameters->contextUnavailable);
    }

    public function testARequiredContextMustBePicked(): void
    {
        $schema = new ParameterSchema(context: true, contextRequired: true);
        self::assertSame($this->accessible[0], (new ReportParametersResolver())->resolve($schema, ['context' => 'project:a1'], $this->accessible)->context);

        $this->expectException(InvalidReportArgumentException::class);
        (new ReportParametersResolver())->resolve($schema, ['context' => 'project:zz'], $this->accessible);
    }
}
