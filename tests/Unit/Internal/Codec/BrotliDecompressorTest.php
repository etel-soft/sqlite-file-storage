<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Internal\Codec\BrotliCompressor;
use Etel\SqliteFileStorage\Internal\Codec\BrotliDecompressor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function random_bytes;
use function str_repeat;
use function substr;

/**
 * @internal
 */
#[CoversClass(BrotliDecompressor::class)]
final class BrotliDecompressorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Round-trips compressed content back to the original')]
    #[RequiresPhpExtension('brotli')]
    public function testRoundtripReturnsOriginal(): void
    {
        $original = str_repeat(string: 'brotli decompressor payload. ', times: 4000);

        $compressor = new BrotliCompressor();
        $compressed = $compressor->add(chunk: $original) . $compressor->finish();

        $decompressor = new BrotliDecompressor();

        $this->assertSame($original, $decompressor->add(chunk: $compressed) . $decompressor->finish());
    }

    #[Test]
    #[TestDox('Throws on corrupted compressed data')]
    #[RequiresPhpExtension('brotli')]
    public function testThrowsOnCorruptedData(): void
    {
        $compressor = new BrotliCompressor();
        $compressed = $compressor->add(chunk: str_repeat(string: 'brotli payload to corrupt. ', times: 4000))
            . $compressor->finish();

        // A valid brotli prefix followed by garbage destroys the stream structure;
        // brotli tolerates truncation and single-byte flips, but not a wrecked body.
        $corrupted = substr(string: $compressed, offset: 0, length: 8) . random_bytes(length: 1024);

        $decompressor = new BrotliDecompressor();

        $this->expectException(ContentIntegrityException::class);

        $decompressor->add(chunk: $corrupted);
        $decompressor->finish();
    }
}
