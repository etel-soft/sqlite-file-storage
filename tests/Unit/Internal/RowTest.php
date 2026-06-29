<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\Row;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(Row::class)]
final class RowTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Reads typed int and string values from a row')]
    public function testReadsTypedValues(): void
    {
        $row = ['size' => 42, 'key' => 'docs/a.txt'];

        $this->assertSame(42, Row::int(row: $row, field: 'size'));
        $this->assertSame('docs/a.txt', Row::string(row: $row, field: 'key'));
    }

    #[Test]
    #[TestDox('Nullable readers return null for missing or null fields')]
    public function testNullableReadersReturnNull(): void
    {
        $row = ['updated_at' => null];

        $this->assertNull(Row::intOrNull(row: $row, field: 'updated_at'));
        $this->assertNull(Row::intOrNull(row: $row, field: 'absent'));
        $this->assertNull(Row::stringOrNull(row: $row, field: 'updated_at'));
        $this->assertNull(Row::stringOrNull(row: $row, field: 'absent'));
    }

    #[Test]
    #[TestDox('Nullable readers return the typed value when present')]
    public function testNullableReadersReturnValue(): void
    {
        $row = ['size' => 7, 'mime' => 'text/plain'];

        $this->assertSame(7, Row::intOrNull(row: $row, field: 'size'));
        $this->assertSame('text/plain', Row::stringOrNull(row: $row, field: 'mime'));
    }

    #[Test]
    #[TestDox('int() throws when the value is not an integer')]
    public function testIntRejectsNonInt(): void
    {
        $this->expectException(StorageIoException::class);

        Row::int(row: ['size' => 'not-an-int'], field: 'size');
    }

    #[Test]
    #[TestDox('string() throws when the value is not a string')]
    public function testStringRejectsNonString(): void
    {
        $this->expectException(StorageIoException::class);

        Row::string(row: ['key' => 123], field: 'key');
    }

    #[Test]
    #[TestDox('intOrNull() throws when a present value has the wrong type')]
    public function testIntOrNullRejectsWrongType(): void
    {
        $this->expectException(StorageIoException::class);

        Row::intOrNull(row: ['size' => 'nope'], field: 'size');
    }

    #[Test]
    #[TestDox('stringOrNull() throws when a present value has the wrong type')]
    public function testStringOrNullRejectsWrongType(): void
    {
        $this->expectException(StorageIoException::class);

        Row::stringOrNull(row: ['key' => 123], field: 'key');
    }
}
