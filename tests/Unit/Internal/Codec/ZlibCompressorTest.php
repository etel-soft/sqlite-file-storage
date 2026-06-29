<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Internal\Codec\ZlibCompressor;
use Etel\SqliteFileStorage\Internal\Codec\ZlibDecompressor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;

use function str_repeat;
use function strlen;
use function substr;

/**
 * @internal
 */
#[CoversClass(ZlibCompressor::class)]
final class ZlibCompressorTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Compresses a multi-chunk payload that decompresses back to the original')]
    #[DataProvider('provideEncodings')]
    public function testCompressedOutputDecompressesToOriginal(int $encoding): void
    {
        $original = str_repeat(string: 'zlib payload spanning several chunks. ', times: 5000);

        $compressor = new ZlibCompressor(encoding: $encoding);
        $compressed = '';
        $total = strlen(string: $original);

        for ($offset = 0; $offset < $total; $offset += 8192) {
            $compressed .= $compressor->add(chunk: substr(string: $original, offset: $offset, length: 8192));
        }

        $compressed .= $compressor->finish();

        $this->assertNotSame($original, $compressed);

        $decompressor = new ZlibDecompressor(encoding: $encoding);

        $this->assertSame($original, $decompressor->add(chunk: $compressed) . $decompressor->finish());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideEncodings(): iterable
    {
        yield 'raw deflate' => [ZLIB_ENCODING_RAW];

        yield 'gzip' => [ZLIB_ENCODING_GZIP];

        yield 'zlib' => [ZLIB_ENCODING_DEFLATE];
    }
}
