<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\ParameterDefinition;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\ParameterType;
use Nowo\ReportKitBundle\Contract\ReportParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_fill;
use function range;
use function str_repeat;

final class ParametersTest extends TestCase
{
    public function testChoiceKeepsAllowedValuesOnly(): void
    {
        $choice = new ParameterDefinition(ParameterType::Choice, 'status', 'open', ['open', 'closed']);
        self::assertSame('closed', $choice->normalise('closed'));
        self::assertSame('open', $choice->normalise('CLOSED'));
        self::assertSame('open', $choice->normalise(['closed']));
        self::assertSame(['open', 'closed'], $choice->choices);
    }

    public function testIntAcceptsDigitsWithinBounds(): void
    {
        $int = new ParameterDefinition(ParameterType::Int, 'top', 10, min: 1, max: 50);
        self::assertSame(20, $int->normalise('20'));
        self::assertSame(5, $int->normalise(5));
        self::assertSame(10, $int->normalise('0'));
        self::assertSame(10, $int->normalise('51'));
        self::assertSame(10, $int->normalise('1e3'));
        self::assertSame(10, $int->normalise(2.5));
        self::assertNull((new ParameterDefinition(ParameterType::Int, 'n'))->normalise('x'));
    }

    public function testBoolUnderstandsQueryStrings(): void
    {
        $bool = new ParameterDefinition(ParameterType::Bool, 'flag', false);
        foreach (['1', 'true', 'ON', 'yes', true] as $yes) {
            self::assertTrue($bool->normalise($yes));
        }
        foreach (['0', 'false', 'off', 'No', false] as $no) {
            self::assertFalse($bool->normalise($no));
        }
        self::assertFalse($bool->normalise('maybe'));
        self::assertFalse($bool->normalise(1));
    }

    public function testDateNeedsARealCalendarDay(): void
    {
        $date = new ParameterDefinition(ParameterType::Date, 'since');
        self::assertSame('2026-02-28', $date->normalise('2026-02-28'));
        self::assertNull($date->normalise('2026-02-30'));
        self::assertNull($date->normalise('2026-2-3'));
        self::assertNull($date->normalise(20260228));
    }

    #[DataProvider('badDefinitions')]
    public function testInvalidDefinitionsAreRefused(callable $build): void
    {
        $this->expectException(InvalidReportArgumentException::class);
        $build();
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function badDefinitions(): iterable
    {
        yield 'empty label' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Int, '')];
        yield 'long label' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Int, str_repeat('k', 201))];
        yield 'choice without choices' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Choice, 'c')];
        yield 'choices on int' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Int, 'c', null, ['a'])];
        yield 'bad choice' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Choice, 'c', 'a', ['a', 'B b'])];
        yield 'too many choices' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Choice, 'c', 'c1', array_fill(0, 101, 'c1'))];
        yield 'bounds on bool' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Bool, 'c', true, min: 1)];
        yield 'min above max' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Int, 'c', null, min: 5, max: 1)];
        yield 'default outside choices' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Choice, 'c', 'x', ['a'])];
        yield 'null default choice' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Choice, 'c', null, ['a'])];
        yield 'null default bool' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Bool, 'c')];
        yield 'default out of bounds' => [static fn (): ParameterDefinition => new ParameterDefinition(ParameterType::Int, 'c', 9, min: 1, max: 5)];
        yield 'no periods' => [static fn (): ParameterSchema => new ParameterSchema([])];
        yield 'zero period' => [static fn (): ParameterSchema => new ParameterSchema([0], 0)];
        yield 'long period' => [static fn (): ParameterSchema => new ParameterSchema([367], 367)];
        yield 'default not a period' => [static fn (): ParameterSchema => new ParameterSchema([7], 30)];
        yield 'required without context' => [static fn (): ParameterSchema => new ParameterSchema(context: false, contextRequired: true)];
        yield 'reserved extra' => [static fn (): ParameterSchema => new ParameterSchema(extra: ['period' => new ParameterDefinition(ParameterType::Int, 'p')])];
        yield 'bad extra name' => [static fn (): ParameterSchema => new ParameterSchema(extra: ['Top' => new ParameterDefinition(ParameterType::Int, 'p')])];
        yield 'extra not a definition' => [static fn (): ParameterSchema => new ParameterSchema(extra: ['top' => new stdClass()])]; // @phpstan-ignore argument.type
        yield 'too many extras' => [static function (): ParameterSchema {
            $extra = [];
            foreach (range(1, 21) as $i) {
                $extra['e' . $i] = new ParameterDefinition(ParameterType::Int, 'e');
            }

            return new ParameterSchema(extra: $extra);
        }];
        yield 'zero days' => [static fn (): ReportParameters => new ReportParameters(0, null)];
        yield 'context and unavailable' => [static fn (): ReportParameters => new ReportParameters(7, new ContextRef('p', '1'), [], true)];
        yield 'bad extra key' => [static fn (): ReportParameters => new ReportParameters(7, null, ['X' => 1])];
        yield 'bad extra value' => [static fn (): ReportParameters => new ReportParameters(7, null, ['x' => 1.5])]; // @phpstan-ignore argument.type
    }

    public function testSchemaDefaultsAndDeduplication(): void
    {
        $schema = new ParameterSchema([7, 7, 30], 30);
        self::assertSame([7, 30], $schema->periods);
        self::assertTrue($schema->context);
        self::assertFalse($schema->contextRequired);
        self::assertSame([], (new ParameterSchema())->extra);
    }
}
