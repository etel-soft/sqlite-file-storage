<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Codec;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\CompressionNotAvailableException;
use Etel\SqliteFileStorage\Internal\Codec\BrotliCompressor;
use Etel\SqliteFileStorage\Internal\Codec\BrotliDecompressor;
use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use Etel\SqliteFileStorage\Internal\Codec\PassthroughCodec;
use Etel\SqliteFileStorage\Internal\Codec\ZlibCompressor;
use Etel\SqliteFileStorage\Internal\Codec\ZlibDecompressor;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function extension_loaded;

/**
 * @internal
 */
#[CoversClass(CodecFactory::class)]
final class CodecFactoryTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Returns a passthrough codec for Compression::None')]
    public function testNoneReturnsPassthrough(): void
    {
        $this->assertInstanceOf(
            PassthroughCodec::class,
            CodecFactory::compressor(compression: Compression::None)
        );
        $this->assertInstanceOf(
            PassthroughCodec::class,
            CodecFactory::decompressor(compression: Compression::None)
        );
    }

    #[Test]
    #[TestDox('Returns zlib-family codecs for deflate, gzip and zlib')]
    public function testZlibFamilyReturnsZlibCodecs(): void
    {
        foreach ([Compression::Deflate, Compression::Gzip, Compression::Zlib] as $compression) {
            $this->assertInstanceOf(
                ZlibCompressor::class,
                CodecFactory::compressor(compression: $compression)
            );
            $this->assertInstanceOf(
                ZlibDecompressor::class,
                CodecFactory::decompressor(compression: $compression)
            );
        }
    }

    #[Test]
    #[TestDox('Returns brotli codecs when the extension is loaded, otherwise throws')]
    public function testBrotliDependsOnExtension(): void
    {
        if (extension_loaded(extension: 'brotli')) {
            $this->assertInstanceOf(
                BrotliCompressor::class,
                CodecFactory::compressor(compression: Compression::Brotli)
            );
            $this->assertInstanceOf(
                BrotliDecompressor::class,
                CodecFactory::decompressor(compression: Compression::Brotli)
            );

            return;
        }

        $this->expectException(CompressionNotAvailableException::class);

        CodecFactory::compressor(compression: Compression::Brotli);
    }
}
