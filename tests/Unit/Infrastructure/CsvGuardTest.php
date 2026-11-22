<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Infrastructure;

use Nowo\ReportKitBundle\Infrastructure\Csv\BuiltInCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Csv\ControllerKitCsvCellGuard;
use Nowo\ReportKitBundle\Infrastructure\Csv\CsvCellGuardInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Q-O4 / REQ-QA-019: ONE table, run against the built-in guard and against ControllerKit's, so both stay identical.
 */
final class CsvGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{CsvCellGuardInterface, float|int|string|null, string}>
     */
    public static function table(): iterable
    {
        $cases = [
            'equals'          => ['=1+1', "'=1+1"],
            'hyperlink'       => ['=HYPERLINK("http://x","y")', "'=HYPERLINK(\"http://x\",\"y\")"],
            'plus'            => ['+1', "'+1"],
            'minus'           => ['-1+2', "'-1+2"],
            'numeric string'  => ['-12.50', "'-12.50"],
            'at'              => ['@SUM(A1)', "'@SUM(A1)"],
            'tab'             => ["\tx", "'\tx"],
            'cr'              => ["\rx", "'\rx"],
            'after semicolon' => ['a;=cmd', 'a; =cmd'],
            'after comma'     => ['a,+1', 'a, +1'],
            'after pipe'      => ['a|@x', 'a| @x'],
            'after newline'   => ["a\n-1", "a\n -1"],
            'both'            => ['=a;=b', "'=a; =b"],
            'plain'           => ['plain text', 'plain text'],
            'inner equals'    => ['a=b', 'a=b'],
            'empty'           => ['', ''],
            'int'             => [-5, '-5'],
            'float'           => [2.5, '2.5'],
            'null'            => [null, ''],
        ];
        foreach (['built-in' => new BuiltInCsvCellGuard(), 'controller-kit' => new ControllerKitCsvCellGuard()] as $name => $guard) {
            foreach ($cases as $case => [$in, $out]) {
                yield $name . ': ' . $case => [$guard, $in, $out];
            }
        }
    }

    #[DataProvider('table')]
    public function testEveryGuardNeutralisesFormulas(CsvCellGuardInterface $guard, int|float|string|null $in, string $out): void
    {
        self::assertSame([$out, 'x'], $guard->row([$in, 'x']));
    }
}
