<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\CompressionNotAvailableException;

use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;

use function function_exists;

/**
 * @internal
 */
final readonly class CodecFactory
{
    public static function compressor(Compression $compression): Compressor
    {
        return match ($compression) {
            Compression::None => new PassthroughCodec(),
            Compression::Deflate => new ZlibCompressor(encoding: ZLIB_ENCODING_RAW),
            Compression::Gzip => new ZlibCompressor(encoding: ZLIB_ENCODING_GZIP),
            Compression::Zlib => new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE),
            Compression::Brotli => self::brotliCompressor()
        };
    }

    public static function decompressor(Compression $compression): Decompressor
    {
        return match ($compression) {
            Compression::None => new PassthroughCodec(),
            Compression::Deflate => new ZlibDecompressor(encoding: ZLIB_ENCODING_RAW),
            Compression::Gzip => new ZlibDecompressor(encoding: ZLIB_ENCODING_GZIP),
            Compression::Zlib => new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            Compression::Brotli => self::brotliDecompressor()
        };
    }

    private static function assertBrotli(): void
    {
        if (!function_exists(function: 'brotli_compress_init')) {
            throw CompressionNotAvailableException::forAlgorithm(compression: Compression::Brotli); // @codeCoverageIgnore
        }
    }

    private static function brotliCompressor(): Compressor
    {
        self::assertBrotli();

        return new BrotliCompressor();
    }

    private static function brotliDecompressor(): Decompressor
    {
        self::assertBrotli();

        return new BrotliDecompressor();
    }
}
