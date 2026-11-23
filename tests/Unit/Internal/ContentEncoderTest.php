<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Internal\ContentEncoder;
use Etel\SqliteFileStorage\Internal\ContentHash;
use Etel\SqliteFileStorage\Internal\Crypto\Encryptor;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\EncodingOutcome;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function fopen;
use function fwrite;
use function gzuncompress;
use function hash;
use function random_bytes;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;

/**
 * @internal
 */
#[CoversClass(ContentEncoder::class)]
final class ContentEncoderTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Auto stores incompressible content as it is, byte for byte')]
    public function testAutoLeavesIncompressibleContent(): void
    {
        $content = random_bytes(length: 1_500_000);

        [$outcome, $stored] = $this->encode(content: $content, compression: new AutoCompression());

        $this->assertSame(Compression::None, $outcome->compression);
        $this->assertSame($content, $stored);
        $this->assertSame(hash(algo: 'sha256', data: $content), $outcome->hash);
        $this->assertSame(1_500_000, $outcome->size);
        $this->assertSame(1_500_000, $outcome->storedSize);
    }

    #[Test]
    #[TestDox('Auto compresses compressible content, the sampled start and the rest alike')]
    public function testAutoCompressesCompressibleContent(): void
    {
        $content = str_repeat(string: 'compressible text, ', times: 100_000);

        [$outcome, $stored] = $this->encode(content: $content, compression: new AutoCompression());

        $this->assertSame(Compression::Zlib, $outcome->compression);
        $this->assertSame($content, gzuncompress(data: $stored));
        $this->assertSame(strlen(string: $stored), $outcome->storedSize);
        $this->assertSame('text/plain', $outcome->mimeType);
    }

    #[Test]
    #[TestDox('Auto keeps a known compressed type as it is, detected from its bytes')]
    public function testAutoLeavesCompressedType(): void
    {
        $content = 'fLaC' . str_repeat(string: "\0", times: 100_000);

        [$outcome, $stored] = $this->encode(content: $content, compression: new AutoCompression());

        $this->assertSame(Compression::None, $outcome->compression);
        $this->assertContains($outcome->mimeType, ['audio/flac', 'audio/x-flac']);
        $this->assertSame($content, $stored);
    }

    #[Test]
    #[TestDox('Requested compression is applied as it is, without a probe')]
    public function testRequestedCompressionIsApplied(): void
    {
        $content = random_bytes(length: 10_000);

        [$outcome, $stored] = $this->encode(content: $content, compression: Compression::Zlib);

        $this->assertSame(Compression::Zlib, $outcome->compression);
        $this->assertSame($content, gzuncompress(data: $stored));
    }

    #[Test]
    #[TestDox('Empty content: no type, one empty final chunk')]
    public function testEmptyContent(): void
    {
        $encryptor = $this->recordingEncryptor();

        [$outcome] = $this->encode(content: '', compression: new AutoCompression(), encryptor: $encryptor);

        $this->assertSame(Compression::None, $outcome->compression);
        $this->assertNull($outcome->mimeType);
        $this->assertSame(0, $outcome->size);
        $this->assertSame([[0, true]], $encryptor->chunks);
    }

    #[Test]
    #[TestDox('Encrypted in whole chunks, only the last one final — also when it is a whole chunk itself')]
    public function testChunkFraming(): void
    {
        $encryptor = $this->recordingEncryptor();

        $this->encode(
            content: random_bytes(length: 2 * ContentEncoder::CHUNK_SIZE),
            compression: Compression::None,
            encryptor: $encryptor
        );

        $this->assertSame(
            [[ContentEncoder::CHUNK_SIZE, false], [ContentEncoder::CHUNK_SIZE, false], [0, true]],
            $encryptor->chunks
        );

        $encryptor = $this->recordingEncryptor();

        $this->encode(
            content: random_bytes(length: 2 * ContentEncoder::CHUNK_SIZE),
            compression: new AutoCompression(),
            encryptor: $encryptor
        );

        $this->assertSame(
            [[ContentEncoder::CHUNK_SIZE, false], [ContentEncoder::CHUNK_SIZE, false], [0, true]],
            $encryptor->chunks
        );
    }

    #[Test]
    #[TestDox('Compressed bytes are cut into whole chunks too, the remainder final')]
    public function testChunksAfterFinish(): void
    {
        $encryptor = $this->recordingEncryptor();

        // random bytes grow slightly in zlib: one whole chunk and a remainder
        $this->encode(
            content: random_bytes(length: ContentEncoder::CHUNK_SIZE),
            compression: Compression::Zlib,
            encryptor: $encryptor
        );

        $this->assertSame(ContentEncoder::CHUNK_SIZE, $encryptor->chunks[0][0]);
        $this->assertFalse($encryptor->chunks[0][1]);
        $this->assertTrue($encryptor->chunks[1][1]);
    }

    #[Test]
    #[TestDox('The outcome carries the hash of the hasher given: keyed under a hash key, not the bare SHA-256')]
    public function testHashesWithTheHasherGiven(): void
    {
        $hasher = new ContentHash(key: str_repeat(string: 'H', times: 32));
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $target = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        $this->assertIsResource($target);
        fwrite(stream: $source, data: 'known file');
        rewind(stream: $source);

        $outcome = new ContentEncoder()->encode(
            source: $source,
            target: $target,
            compression: Compression::None,
            encryptor: new PassthroughCrypto(),
            hash: $hasher
        );

        $this->assertSame($hasher->of(content: 'known file'), $outcome->hash);
        $this->assertNotSame(hash(algo: 'sha256', data: 'known file'), $outcome->hash);
    }

    /**
     * @return array{EncodingOutcome, string}
     */
    private function encode(
        string $content,
        AutoCompression|Compression $compression,
        ?Encryptor $encryptor = null
    ): array {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $target = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        $this->assertIsResource($target);
        fwrite(stream: $source, data: $content);
        rewind(stream: $source);

        $outcome = new ContentEncoder()->encode(
            source: $source,
            target: $target,
            compression: $compression,
            encryptor: $encryptor ?? new PassthroughCrypto()
        );
        rewind(stream: $target);

        return [$outcome, (string) stream_get_contents(stream: $target)];
    }

    private function recordingEncryptor(): RecordingEncryptor
    {
        return new RecordingEncryptor();
    }
}

/**
 * @internal
 */
final class RecordingEncryptor implements Encryptor
{
    /** @var list<array{int, bool}> Plaintext length and the final flag of each chunk */
    public array $chunks = [];

    #[Override]
    public function encryptChunk(string $plaintext, bool $final): string
    {
        $this->chunks[] = [strlen(string: $plaintext), $final];

        return $plaintext;
    }

    #[Override]
    public function header(): string
    {
        return '';
    }
}
