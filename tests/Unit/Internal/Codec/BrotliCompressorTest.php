<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Internal\Codec\BrotliCompressor;
use Etel\SqliteFileStorage\Internal\Codec\BrotliDecompressor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function str_repeat;
use function strlen;
use function substr;

/**
 * @internal
 */
#[CoversClass(BrotliCompressor::class)]
final class BrotliCompressorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Compresses a multi-chunk payload that decompresses back to the original')]
    #[RequiresPhpExtension('brotli')]
    public function testCompressedOutputDecompressesToOriginal(): void
    {
        $original = str_repeat(string: 'brotli payload spanning several chunks. ', times: 5000);

        $compressor = new BrotliCompressor();
        $compressed = '';
        $total = strlen(string: $original);

        for ($offset = 0; $offset < $total; $offset += 8192) {
            $compressed .= $compressor->add(chunk: substr(string: $original, offset: $offset, length: 8192));
        }

        $compressed .= $compressor->finish();

        $this->assertNotSame($original, $compressed);

        $decompressor = new BrotliDecompressor();

        $this->assertSame($original, $decompressor->add(chunk: $compressed) . $decompressor->finish());
    }
}
