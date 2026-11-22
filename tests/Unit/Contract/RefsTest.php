<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Tests\Unit\Contract;

use Nowo\ReportKitBundle\Contract\ContextRef;
use Nowo\ReportKitBundle\Contract\Exception\InvalidReportArgumentException;
use Nowo\ReportKitBundle\Contract\Exception\ReportKitExceptionInterface;
use Nowo\ReportKitBundle\Contract\OwnerRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class RefsTest extends TestCase
{
    public function testOwnerAndContextCompareExactly(): void
    {
        $owner = new OwnerRef('user', 'UserA');
        self::assertSame('user:UserA', $owner->key());
        self::assertTrue($owner->equals(new OwnerRef('user', 'UserA')));
        self::assertFalse($owner->equals(new OwnerRef('user', 'usera')));
        self::assertFalse($owner->equals(new OwnerRef('team', 'UserA')));

        $context = new ContextRef('project', 'Acme-1');
        self::assertSame('project:Acme-1', $context->key());
        self::assertTrue($context->equals(new ContextRef('project', 'Acme-1')));
        self::assertFalse($context->equals(new ContextRef('project', 'acme-1')));
        self::assertFalse($context->equals(new ContextRef('board', 'Acme-1')));
    }

    public function testContextKeysParseBackOrGiveNull(): void
    {
        self::assertEquals(new ContextRef('project', 'a:b'), ContextRef::tryFromKey('project:a:b'));
        self::assertNull(ContextRef::tryFromKey('project'));
        self::assertNull(ContextRef::tryFromKey('Project:1'));
        self::assertNull(ContextRef::tryFromKey('project: 1'));
    }

    #[DataProvider('badReferences')]
    public function testInvalidReferencesAreRefusedNeverTrimmed(string $type, string $id): void
    {
        try {
            new OwnerRef($type, $id);
            self::fail('accepted');
        } catch (ReportKitExceptionInterface $e) {
            self::assertInstanceOf(InvalidReportArgumentException::class, $e);
        }
        $this->expectException(InvalidReportArgumentException::class);
        new ContextRef($type, $id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badReferences(): iterable
    {
        yield 'type upper case' => ['User', '1'];
        yield 'empty id' => ['user', ''];
        yield 'leading space' => ['user', ' 1'];
        yield 'trailing nbsp' => ['user', "1\u{00A0}"];
        yield 'zero width' => ['user', "a\u{200B}b"];
        yield 'control' => ['user', "a\nb"];
        yield 'not utf-8' => ['user', "\xFF"];
        yield 'too long' => ['user', str_repeat('x', 129)];
    }

    public function testContextIdsAreShorterThanOwnerIds(): void
    {
        new OwnerRef('user', str_repeat('x', 128));
        $this->expectException(InvalidReportArgumentException::class);
        new ContextRef('project', str_repeat('x', 65));
    }
}
