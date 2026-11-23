<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Internal\Codec\PassthroughCodec;
use Etel\SqliteFileStorage\Internal\Codec\ZlibCompressor;
use Etel\SqliteFileStorage\Internal\Codec\ZlibDecompressor;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\DecodingStreamWrapper;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;

use const SEEK_CUR;
use const SEEK_END;
use const ZLIB_ENCODING_DEFLATE;

use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function hash;
use function in_array;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function random_bytes;
use function rewind;
use function str_repeat;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function substr;
use function trim;

/**
 * @internal
 */
#[CoversClass(DecodingStreamWrapper::class)]
final class DecodingStreamWrapperTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Reads plain content back across multiple fill chunks')]
    public function testReadsPlainContentAcrossChunks(): void
    {
        $content = str_repeat(string: 'decoding-stream-wrapper payload. ', times: 20000);

        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        fwrite(stream: $source, data: $content);
        rewind(stream: $source);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: strlen(string: $content),
            plainSize: strlen(string: $content)
        );

        $stat = fstat(stream: $decoded);
        $this->assertNotFalse($stat);
        $this->assertSame(strlen(string: $content), $stat['size']);
        $this->assertSame($content, stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Reads content that expands a thousandfold without holding more than a few MiB of it')]
    public function testHighlyCompressedContentIsDecodedInBoundedMemory(): void
    {
        $size = 64 * 1024 * 1024;
        $zeros = str_repeat(string: "\0", times: 1024 * 1024);
        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        $storedSize = 0;

        for ($written = 0; $written < $size; $written += strlen(string: $zeros)) {
            $storedSize += (int) fwrite(stream: $source, data: $compressor->add(chunk: $zeros));
        }

        $storedSize += (int) fwrite(stream: $source, data: $compressor->finish());
        rewind(stream: $source);
        unset($zeros);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: $storedSize,
            plainSize: $size
        );

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $read = 0;
        $nonZero = 0;

        while (!feof(stream: $decoded)) {
            $part = (string) fread(stream: $decoded, length: 8192);
            $read += strlen(string: $part);
            $nonZero += strlen(string: trim(string: $part, characters: "\0"));
        }

        $this->assertSame($size, $read);
        $this->assertSame(0, $nonZero);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    #[Test]
    #[TestDox('Slices grown over content that barely expands shrink where zeros follow, in bounded memory')]
    public function testSlicesShrinkWhereContentStartsToExpand(): void
    {
        $noise = random_bytes(length: 2 * 1024 * 1024);
        $zeros = str_repeat(string: "\0", times: 1024 * 1024);
        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $stored = $compressor->add(chunk: $noise);

        for ($megabyte = 0; $megabyte < 64; ++$megabyte) {
            $stored .= $compressor->add(chunk: $zeros);
        }

        $stored .= $compressor->finish();
        unset($zeros);

        $decoded = DecodingStreamWrapper::open(
            source: self::sourceOf(data: $stored),
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: strlen(string: $stored),
            plainSize: strlen(string: $noise) + 64 * 1024 * 1024
        );

        /* @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertSame($noise, stream_get_contents(stream: $decoded, length: strlen(string: $noise)));

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $read = 0;

        while (!feof(stream: $decoded)) {
            $read += strlen(string: (string) fread(stream: $decoded, length: 1024 * 1024));
        }

        $this->assertSame(64 * 1024 * 1024, $read);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * @param positive-int $readSize
     */
    #[Test]
    #[TestDox('Reads the same bytes whatever the size of a read')]
    #[TestWith([1])]
    #[TestWith([7])]
    #[TestWith([8192])]
    #[TestWith([1_000_000])]
    public function testReadSizesGiveTheSameContent(int $readSize): void
    {
        $content = '';

        /* @noinspection SlowArrayOperationsInLoopInspection */
        for ($line = 0; strlen(string: $content) < 300_000; ++$line) {
            $content .= 'line ' . $line . ' of compressible text, ' . hash(algo: 'crc32b', data: (string) $line) . "\n";
        }

        if ($readSize < 100) {
            $content = substr(string: $content, offset: 0, length: 20_000);
        }

        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $stored = $compressor->add(chunk: $content) . $compressor->finish();
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        fwrite(stream: $source, data: $stored);
        rewind(stream: $source);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: strlen(string: $stored),
            plainSize: strlen(string: $content)
        );

        $read = '';

        while (!feof(stream: $decoded)) {
            /* @noinspection PhpUnnecessaryStringCastInspection */
            /* @noinspection UnnecessaryCastingInspection */
            $read .= (string) fread(stream: $decoded, length: $readSize);
        }

        $this->assertSame($content, $read);
    }

    #[Test]
    #[TestDox('Decodes empty content to an empty stream')]
    public function testDecodesEmptyContent(): void
    {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: 0,
            plainSize: 0
        );

        $this->assertSame('', stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Registers its stream wrapper protocol on first use')]
    public function testRegistersStreamWrapperProtocol(): void
    {
        if (in_array(needle: DecodingStreamWrapper::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister(protocol: DecodingStreamWrapper::PROTOCOL);
        }

        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);

        DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: 0,
            plainSize: 0
        );

        $this->assertContains(DecodingStreamWrapper::PROTOCOL, stream_get_wrappers());
    }

    #[Test]
    #[TestDox('Refuses to open when the stream context is missing required options')]
    public function testRejectsInvalidStreamContext(): void
    {
        if (!in_array(needle: DecodingStreamWrapper::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(protocol: DecodingStreamWrapper::PROTOCOL, class: DecodingStreamWrapper::class);
        }

        $context = stream_context_create(options: [
            DecodingStreamWrapper::PROTOCOL => ['source' => 'not-a-resource'],
        ]);

        $this->assertFalse(@fopen(
            filename: DecodingStreamWrapper::PROTOCOL . '://object',
            mode: 'rb',
            context: $context
        ));
    }

    #[Test]
    #[TestDox('Raw content seeks anywhere, at once: from the start, the current position and the end')]
    public function testRawContentSeeksAnywhere(): void
    {
        $content = self::numberedContent(bytes: 1_000_000);
        // the content starts after a prefix of the source: positions are the content's own
        $decoded = self::open(source: self::sourceOf(data: 'PREFIX' . $content, from: 6), content: $content);

        $this->assertSame(0, fseek(stream: $decoded, offset: 500_000));
        $this->assertSame(500_000, ftell(stream: $decoded));
        $this->assertSame(substr(string: $content, offset: 500_000, length: 100), fread(stream: $decoded, length: 100));

        $this->assertSame(0, fseek(stream: $decoded, offset: 10));
        $this->assertSame(substr(string: $content, offset: 10, length: 50), fread(stream: $decoded, length: 50));

        $this->assertSame(0, fseek(stream: $decoded, offset: 1000, whence: SEEK_CUR));
        $this->assertSame(substr(string: $content, offset: 1060, length: 20), fread(stream: $decoded, length: 20));

        $this->assertSame(0, fseek(stream: $decoded, offset: -30, whence: SEEK_END));
        $this->assertSame(substr(string: $content, offset: -30), stream_get_contents(stream: $decoded));
        $this->assertTrue(feof(stream: $decoded));

        $this->assertSame(0, fseek(stream: $decoded, offset: 0));
        $this->assertSame($content, stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('A seek before the start, or of an unknown kind, fails; one past the end stops at the end')]
    public function testSeekOutsideTheContent(): void
    {
        $content = self::numberedContent(bytes: 10_000);
        $decoded = self::open(source: self::sourceOf(data: $content), content: $content);

        $this->assertSame(-1, fseek(stream: $decoded, offset: -10_001, whence: SEEK_END));
        $this->assertSame(-1, fseek(stream: $decoded, offset: 0, whence: 99));
        $this->assertSame(0, ftell(stream: $decoded));

        $this->assertSame(0, fseek(stream: $decoded, offset: 20_000));
        $this->assertSame(10_000, ftell(stream: $decoded));
        $this->assertSame('', stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Compressed content seeks forward by decoding what it skips, and back by decoding from the start again')]
    public function testCompressedContentSeeksBothWays(): void
    {
        $content = self::numberedContent(bytes: 1_000_000);
        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $stored = $compressor->add(chunk: $content) . $compressor->finish();
        $decoded = DecodingStreamWrapper::open(
            source: self::sourceOf(data: $stored),
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: strlen(string: $stored),
            plainSize: strlen(string: $content)
        );

        $this->assertSame(0, fseek(stream: $decoded, offset: 700_000));
        $this->assertSame(700_000, ftell(stream: $decoded));
        $this->assertSame(substr(string: $content, offset: 700_000, length: 64), fread(stream: $decoded, length: 64));

        $this->assertSame(0, fseek(stream: $decoded, offset: 123_456));
        $this->assertSame(substr(string: $content, offset: 123_456, length: 64), fread(stream: $decoded, length: 64));

        $this->assertSame(0, fseek(stream: $decoded, offset: 0));
        $this->assertSame($content, stream_get_contents(stream: $decoded));

        $this->assertSame(0, fseek(stream: $decoded, offset: -10, whence: SEEK_END));
        $this->assertSame(substr(string: $content, offset: -10), stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('A forward seek past what the content really holds fails: the content is shorter than recorded')]
    public function testForwardSeekPastTheRealContentFails(): void
    {
        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        $stored = $compressor->add(chunk: 'short') . $compressor->finish();
        $decoded = DecodingStreamWrapper::open(
            source: self::sourceOf(data: $stored),
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: strlen(string: $stored),
            plainSize: 1000
        );

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('shorter than its recorded size');

        fseek(stream: $decoded, offset: 500);
    }

    #[Test]
    #[TestDox('Raw content over a source that does not seek does not seek either')]
    public function testRawContentOverUnseekableSourceDoesNotSeek(): void
    {
        if (!in_array(needle: UnseekableStream::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(protocol: UnseekableStream::PROTOCOL, class: UnseekableStream::class);
        }

        UnseekableStream::$content = UnseekableStream::CONTENT;
        $source = fopen(filename: UnseekableStream::PROTOCOL . '://content', mode: 'rb');
        $this->assertIsResource($source);
        $decoded = self::open(source: $source, content: UnseekableStream::CONTENT);

        $this->assertSame(-1, fseek(stream: $decoded, offset: 3));
        $this->assertSame(UnseekableStream::CONTENT, stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Compressed content over a source that does not seek seeks forward, but not back')]
    public function testCompressedContentOverUnseekableSourceSeeksForwardOnly(): void
    {
        if (!in_array(needle: UnseekableStream::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(protocol: UnseekableStream::PROTOCOL, class: UnseekableStream::class);
        }

        $content = self::numberedContent(bytes: 100_000);
        $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
        UnseekableStream::$content = $compressor->add(chunk: $content) . $compressor->finish();
        $source = fopen(filename: UnseekableStream::PROTOCOL . '://content', mode: 'rb');
        $this->assertIsResource($source);
        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE),
            cipherChunkSize: 0,
            storedSize: strlen(string: UnseekableStream::$content),
            plainSize: strlen(string: $content)
        );

        $this->assertSame(0, fseek(stream: $decoded, offset: 50_000));
        $this->assertSame(substr(string: $content, offset: 50_000, length: 10), fread(stream: $decoded, length: 10));
        $this->assertSame(-1, fseek(stream: $decoded, offset: 0));
    }

    #[Test]
    #[TestDox('Content that ends before its recorded size fails instead of reading as whole')]
    #[TestWith([true])]
    #[TestWith([false])]
    public function testContentShorterThanRecordedFails(bool $compressed): void
    {
        $content = self::numberedContent(bytes: 60_000);
        $stored = $content;

        if ($compressed) {
            $compressor = new ZlibCompressor(encoding: ZLIB_ENCODING_DEFLATE);
            $stored = $compressor->add(chunk: $content) . $compressor->finish();
        }

        $stored = substr(string: $stored, offset: 0, length: -20);
        $decoded = DecodingStreamWrapper::open(
            source: self::sourceOf(data: $stored),
            decryptor: new PassthroughCrypto(),
            decompressor: $compressed ? new ZlibDecompressor(encoding: ZLIB_ENCODING_DEFLATE) : new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: strlen(string: $stored),
            plainSize: strlen(string: $content)
        );

        $this->expectException(ContentIntegrityException::class);

        stream_get_contents(stream: $decoded);
    }

    #[Test]
    #[TestDox('Content that goes on past its recorded size fails')]
    public function testContentLongerThanRecordedFails(): void
    {
        $content = self::numberedContent(bytes: 60_000);
        $decoded = DecodingStreamWrapper::open(
            source: self::sourceOf(data: $content),
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: strlen(string: $content),
            plainSize: 1000
        );

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('longer than its recorded size');

        stream_get_contents(stream: $decoded);
    }

    /**
     * @param resource $source
     *
     * @return resource
     */
    private static function open(mixed $source, string $content)
    {
        return DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: strlen(string: $content),
            plainSize: strlen(string: $content)
        );
    }

    private static function numberedContent(int $bytes): string
    {
        $content = '';

        /* @noinspection SlowArrayOperationsInLoopInspection */
        for ($line = 0; strlen(string: $content) < $bytes; ++$line) {
            $content .= $line . "\n";
        }

        return substr(string: $content, offset: 0, length: $bytes);
    }

    /**
     * @return resource
     */
    private static function sourceOf(string $data, int $from = 0)
    {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        self::assertIsResource($source);
        fwrite(stream: $source, data: $data);
        fseek(stream: $source, offset: $from);

        return $source;
    }
}

/**
 * A readable stream that refuses to seek, as a pipe or a socket does.
 *
 * @internal
 */
final class UnseekableStream
{
    public const string CONTENT = 'content of a stream that does not seek';

    public const string PROTOCOL = 'sfs-unseekable';

    /** What the next stream opened gives */
    public static string $content = self::CONTENT;

    /** @var null|resource set by PHP runtime */
    public $context;

    private int $offset = 0;

    private string $data = '';

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(string: $this->data);
    }

    /* @noinspection PhpUnusedParameterInspection */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->data = self::$content;

        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr(string: $this->data, offset: $this->offset, length: $count);
        $this->offset += strlen(string: $data);

        return $data;
    }

    /* @noinspection PhpUnusedParameterInspection */
    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }
}
