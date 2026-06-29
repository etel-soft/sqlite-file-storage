<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Internal\Codec\ZlibCompressor;
use Etel\SqliteFileStorage\Internal\Codec\ZlibDecompressor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const ZLIB_ENCODING_DEFLATE;

use function chr;
use function ord;
use function random_bytes;
use function str_repeat;
use function strlen;
use function substr;

/**
 * @internal
 */
#[CoversClass(ZlibDecompressor::class)]
final class ZlibDecompressorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Round-trips compressed content back to the original')]
    public function testRoundtripReturnsOriginal(): void
    {
        $original = str_repeat(string: 'decompressor payload. ', times: 4000);

        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $compressed = $compressor->add(chunk: $original) . $compressor->finish();

        $decompressor = new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE);

        $this->assertSame($original, $decompressor->add(chunk: $compressed) . $decompressor->finish());
    }

    #[Test]
    #[TestDox('Throws on corrupted compressed data')]
    public function testThrowsOnCorruptedData(): void
    {
        $decompressor = new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE);

        $this->expectException(ContentIntegrityException::class);

        $decompressor->add(chunk: random_bytes(length: 256));
    }

    #[Test]
    #[TestDox('Throws when the trailing checksum no longer matches the content')]
    public function testThrowsOnCorruptedChecksum(): void
    {
        $original = str_repeat(string: 'checksum detection payload. ', times: 4000);

        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $compressed = $compressor->add(chunk: $original) . $compressor->finish();

        $lastByte = $compressed[strlen(string: $compressed) - 1];
        $corrupted = substr(string: $compressed, offset: 0, length: -1)
            . chr(codepoint: ord(character: $lastByte) ^ 0xFF);

        $decompressor = new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE);

        $this->expectException(ContentIntegrityException::class);

        $decompressor->add(chunk: $corrupted) . $decompressor->finish();
    }
}
