<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const JSON_THROW_ON_ERROR;

use function array_map;
use function json_encode;

/**
 * @internal
 */
#[CoversClass(Compression::class)]
final class CompressionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Each case maps to its expected string value')]
    public function testCaseValues(): void
    {
        $this->assertSame('brotli', Compression::Brotli->value);
        $this->assertSame('deflate', Compression::Deflate->value);
        $this->assertSame('gzip', Compression::Gzip->value);
        $this->assertSame('none', Compression::None->value);
        $this->assertSame('zlib', Compression::Zlib->value);
    }

    #[Test]
    #[TestDox('Only algorithms are cases: deciding per object is an AutoCompression, never stored')]
    public function testOnlyAlgorithmsAreCases(): void
    {
        $this->assertSame(
            ['brotli', 'deflate', 'gzip', 'none', 'zlib'],
            array_map(callback: static fn (Compression $case): string => $case->value, array: Compression::cases())
        );
    }

    #[Test]
    #[TestDox('Encoding to JSON returns the backing value for every case')]
    public function testJsonSerializationReturnsValue(): void
    {
        foreach (Compression::cases() as $case) {
            $this->assertSame('"' . $case->value . '"', json_encode(value: $case, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    #[TestDox('Method from() round-trips a backing value to its case')]
    public function testFromRoundTrip(): void
    {
        $this->assertSame(Compression::Zlib, Compression::from(value: 'zlib'));
        $this->assertSame(Compression::Brotli, Compression::from(value: Compression::Brotli->value));
    }
}
