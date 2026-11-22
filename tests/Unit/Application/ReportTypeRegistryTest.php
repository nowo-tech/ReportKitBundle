<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\ReportKitBundle\Application\ReportTypeRegistry;
use Nowo\ReportKitBundle\Contract\ParameterSchema;
use Nowo\ReportKitBundle\Contract\Report;
use Nowo\ReportKitBundle\Contract\ReportRequest;
use Nowo\ReportKitBundle\Contract\ReportTypeInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;

final class ReportTypeRegistryTest extends TestCase
{
    public function testConfiguredOrderFirstThenByKeyWithoutDisabled(): void
    {
        $registry = new ReportTypeRegistry([self::type('zeta'), self::type('alpha'), self::type('mid'), self::type('gone')], ['mid', 'unknown'], ['gone']);

        self::assertSame(['mid', 'alpha', 'zeta'], array_keys($registry->all()));
        self::assertSame('alpha', $registry->get('alpha')?->key());
        self::assertNull($registry->get('gone'));
        self::assertNull($registry->get('unknown'));
    }

    public function testDuplicateKeysAreRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two report types use the key "alpha"');
        new ReportTypeRegistry([self::type('alpha'), self::type('alpha')]);
    }

    public function testInvalidKeysAreRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Report type key must match');
        new ReportTypeRegistry([self::type('Bad Key')]);
    }

    public function testTaggedServicesMustBeReportTypes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');
        new ReportTypeRegistry([new stdClass()]);
    }

    public static function type(string $key): ReportTypeInterface
    {
        return new class($key) implements ReportTypeInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function labelKey(): string
            {
                return 'l';
            }

            public function descriptionKey(): string
            {
                return 'd';
            }

            public function translationDomain(): ?string
            {
                return null;
            }

            public function icon(): string
            {
                return 'i';
            }

            public function parameters(): ParameterSchema
            {
                return new ParameterSchema();
            }

            public function permission(): ?string
            {
                return null;
            }

            public function build(ReportRequest $request): Report
            {
                throw new LogicException('not built in this test');
            }
        };
    }
}
