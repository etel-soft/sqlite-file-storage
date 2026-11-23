<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\EncodedContent;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\FileCodec;
use Etel\SqliteFileStorage\KeyProvider;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;

use const PHP_INT_MAX;

use function fopen;
use function fseek;
use function fwrite;
use function hash;
use function random_bytes;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;

/**
 * @internal
 */
#[CoversClass(FileCodec::class)]
final class FileCodecTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Encodes then decodes content back to the original bytes for every codec pair')]
    #[DataProvider('provideCodecMatrix')]
    public function testRoundtrip(Compression $compression, Encryption $encryption): void
    {
        $codec = $this->stubbedCodec();
        $content = random_bytes(length: 700000);

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: $content,
            compression: $compression,
            encryption: $encryption
        );

        $this->assertSame(hash(algo: 'sha256', data: $content), $encoded->hash);
        $this->assertSame(strlen(string: $content), $encoded->size);
        $this->assertSame($compression, $encoded->compression);
        $this->assertSame($encryption, $encoded->encryption);

        $decoded = $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: $compression,
            encryption: $encryption,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: $encoded->encryptionHeader,
            chunkSize: $encoded->chunkSize
        );

        $this->assertSame($content, stream_get_contents(stream: $decoded));
    }

    /**
     * @return iterable<string, array{Compression, Encryption}>
     */
    public static function provideCodecMatrix(): iterable
    {
        foreach ([Compression::None, Compression::Zlib] as $compression) {
            foreach ([Encryption::None, Encryption::XChaCha20Poly1305] as $encryption) {
                yield $compression->value . ' + ' . $encryption->value => [$compression, $encryption];
            }
        }
    }

    #[Test]
    #[TestDox('Without a hash key contentHash() is the SHA-256 that encode() gives')]
    public function testContentHashWithoutKeyIsSha256(): void
    {
        $codec = new FileCodec();

        [$encoded] = $this->encodeToTemp(
            codec: $codec,
            content: 'known file',
            compression: Compression::None,
            encryption: Encryption::None
        );

        $this->assertSame(hash(algo: 'sha256', data: 'known file'), $encoded->hash);
        $this->assertSame($encoded->hash, $codec->contentHash(content: 'known file'));
    }

    #[Test]
    #[TestDox('With a hash key encode() gives a keyed hash, the one contentHash() gives, and the content decodes')]
    public function testHashKeyGivesKeyedHash(): void
    {
        $codec = new FileCodec(
            keyProvider: new ArrayKeyProvider(
                keys: ['hash' => str_repeat(string: 'H', times: 32)],
                currentKeyId: 'hash'
            ),
            hashKeyId: 'hash'
        );

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'known file',
            compression: Compression::Zlib,
            encryption: Encryption::None
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $encoded->hash);
        $this->assertNotSame(hash(algo: 'sha256', data: 'known file'), $encoded->hash);
        $this->assertSame($encoded->hash, $codec->contentHash(content: 'known file'));

        $decoded = $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: $encoded->compression,
            encryption: Encryption::None
        );

        $this->assertSame('known file', stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Compression shrinks the stored bytes below the plain size')]
    public function testCompressionShrinksTheStoredBytes(): void
    {
        $codec = $this->stubbedCodec();
        $content = str_repeat(string: 'compressible file-store payload. ', times: 30000);

        [$encoded] = $this->encodeToTemp(
            codec: $codec,
            content: $content,
            compression: Compression::Zlib,
            encryption: Encryption::XChaCha20Poly1305
        );

        $this->assertLessThan($encoded->size, $encoded->storedSize);
        $this->assertNotNull($encoded->encryptionKeyId);
        $this->assertNotNull($encoded->encryptionHeader);
    }

    #[Test]
    #[TestDox('Encrypted output never contains the plaintext marker')]
    public function testEncryptedBytesDoNotLeakPlaintext(): void
    {
        $codec = $this->stubbedCodec();
        $marker = 'PLAINTEXT-MARKER-' . hash(algo: 'sha256', data: 'x');

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: $marker . str_repeat(string: 'A', times: 100000),
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );

        $this->assertStringNotContainsString($marker, (string) stream_get_contents(stream: $file));
        // content fits one chunk: stored = plain + one AEAD tag
        $this->assertSame($encoded->size + 17, $encoded->storedSize);
    }

    #[Test]
    #[TestDox('Decoding with the wrong key fails the integrity check')]
    public function testWrongKeyFailsIntegrity(): void
    {
        $codec = $this->stubbedCodec();

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'secret content',
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );

        $wrongKey = $this->stubbedCodec(keySeed: 'B');

        $decoded = $wrongKey->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: $encoded->encryptionHeader
        );

        $this->expectException(ContentIntegrityException::class);

        stream_get_contents(stream: $decoded);
    }

    #[Test]
    #[TestDox('Resolves the encryption key through the KeyProvider by its recorded id')]
    public function testResolvesKeyThroughProvider(): void
    {
        $keyProvider = $this->createMockConfig(type: KeyProvider::class)
            ->addMethodReturns(name: 'getCurrentKeyId', return: 'main')
            ->addMethodReturns(
                name: 'getKey',
                arguments: ['main'],
                return: str_repeat(string: 'A', times: 32),
                calls: 2
            )
            ->getSealedMock();

        $codec = new FileCodec(keyProvider: $keyProvider);

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'verify key resolution',
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );

        $decoded = $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: $encoded->encryptionHeader
        );

        $this->assertSame('verify key resolution', stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Empty content encodes with no detected mime type and zero stored bytes')]
    public function testEmptyContentEncoding(): void
    {
        $codec = $this->stubbedCodec();

        [$encoded] = $this->encodeToTemp(
            codec: $codec,
            content: '',
            compression: Compression::None,
            encryption: Encryption::None
        );

        $this->assertSame(0, $encoded->size);
        $this->assertSame(0, $encoded->storedSize);
        $this->assertNull($encoded->mimeType);
        $this->assertNull($encoded->encryptionKeyId);
    }

    #[Test]
    #[TestDox('Encoding with a wrong-length key throws InvalidEncryptionKeyException')]
    public function testEncodeWithInvalidKeyLengthThrows(): void
    {
        $codec = new FileCodec(keyProvider: $this->createStubConfig(type: KeyProvider::class)
            ->addMethodReturns(name: 'getCurrentKeyId', return: 'main')
            ->addMethodReturns(name: 'getKey', return: 'too-short-key')
            ->getSealedStub());

        $this->expectException(InvalidEncryptionKeyException::class);

        $this->encodeToTemp(
            codec: $codec,
            content: 'data',
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );
    }

    #[Test]
    #[TestDox('Decoding encrypted content without the key id or header fails integrity')]
    public function testDecodeWithoutEncryptionContextThrows(): void
    {
        $codec = $this->stubbedCodec();

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'secret',
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );

        $this->expectException(ContentIntegrityException::class);

        $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionHeader: $encoded->encryptionHeader
        );
    }

    #[Test]
    #[TestDox('Decoding with a malformed base64 encryption header fails integrity')]
    public function testDecodeWithMalformedHeaderThrows(): void
    {
        $codec = $this->stubbedCodec();

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'secret',
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305
        );

        $this->expectException(ContentIntegrityException::class);

        $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: 'not valid base64 !!'
        );
    }

    #[Test]
    #[TestDox('Auto reports the compression it applied, and the content decodes with it')]
    #[TestWith(['random', 'none'])]
    #[TestWith(['text', 'zlib'])]
    public function testAutoReportsTheAppliedCompression(string $kind, string $applied): void
    {
        $codec = $this->stubbedCodec();
        $content = $kind === 'random'
            ? random_bytes(length: 300_000)
            : str_repeat(string: 'text, ', times: 50_000);

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: $content,
            compression: new AutoCompression(),
            encryption: Encryption::XChaCha20Poly1305
        );

        $this->assertSame(Compression::from(value: $applied), $encoded->compression);

        $decoded = $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: $encoded->compression,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: $encoded->encryptionHeader
        );

        $this->assertSame($content, stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Content bound to associated data decodes with the same data and fails with other')]
    public function testAssociatedDataBindsTheContent(): void
    {
        $codec = $this->stubbedCodec();
        $content = random_bytes(length: 600_000);

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: $content,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            associatedData: 'bucket/ref-1'
        );

        // decoding closes the stream it reads: the second read gets a copy of the bytes
        $copy = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($copy);
        fwrite(stream: $copy, data: (string) stream_get_contents(stream: $file));
        rewind(stream: $copy);
        rewind(stream: $file);

        $this->assertSame($content, stream_get_contents(stream: $this->decodeBound(
            codec: $codec,
            encoded: $encoded,
            file: $file,
            associatedData: 'bucket/ref-1'
        )));

        $moved = $this->decodeBound(codec: $codec, encoded: $encoded, file: $copy, associatedData: 'bucket/ref-2');

        $this->expectException(ContentIntegrityException::class);

        stream_get_contents(stream: $moved);
    }

    #[Test]
    #[TestDox('Associated data without encryption is refused: nothing would hold it')]
    public function testAssociatedDataWithoutEncryptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('encrypted content only');

        $this->encodeToTemp(
            codec: $this->stubbedCodec(),
            content: 'plain bytes',
            compression: Compression::None,
            encryption: Encryption::None,
            associatedData: 'bucket/ref-1'
        );
    }

    #[Test]
    #[TestDox('Plain content is refused when associated data is given to decode it')]
    public function testPlainContentIsRefusedWithAssociatedData(): void
    {
        $codec = $this->stubbedCodec();

        [$encoded, $file] = $this->encodeToTemp(
            codec: $codec,
            content: 'plain bytes',
            compression: Compression::None,
            encryption: Encryption::None
        );

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('content is not encrypted');

        $this->decodeBound(codec: $codec, encoded: $encoded, file: $file, associatedData: 'bucket/ref-1');
    }

    #[Test]
    #[TestDox('A stored file cut short does not decode as whole')]
    public function testTruncatedPlainContentFails(): void
    {
        $codec = new FileCodec();
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $target = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        $this->assertIsResource($target);
        fwrite(stream: $source, data: str_repeat(string: 'hello world ', times: 5000));
        rewind(stream: $source);

        $encoded = $codec->encode(
            source: $source,
            target: $target,
            compression: Compression::Zlib,
            encryption: Encryption::None
        );
        rewind(stream: $target);

        $decoded = $codec->decode(
            source: $target,
            storedSize: $encoded->storedSize - 20,
            plainSize: $encoded->size,
            compression: $encoded->compression,
            encryption: $encoded->encryption
        );

        $this->expectException(ContentIntegrityException::class);

        stream_get_contents(stream: $decoded);
    }

    #[Test]
    #[TestDox('A chunk size out of range is refused for encrypted content')]
    #[TestWith([0])]
    #[TestWith([-17])]
    #[TestWith([PHP_INT_MAX])]
    public function testChunkSizeOutOfRangeIsRefused(int $chunkSize): void
    {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('malformed chunk size');

        new FileCodec()->decode(
            source: $source,
            storedSize: 17,
            plainSize: 0,
            compression: Compression::None,
            encryption: Encryption::XChaCha20Poly1305,
            encryptionKeyId: 'k1',
            encryptionHeader: 'AAAA',
            chunkSize: $chunkSize
        );
    }

    /**
     * @param resource $file
     *
     * @return resource
     */
    private function decodeBound(FileCodec $codec, EncodedContent $encoded, mixed $file, string $associatedData)
    {
        return $codec->decode(
            source: $file,
            storedSize: $encoded->storedSize,
            plainSize: $encoded->size,
            compression: $encoded->compression,
            encryption: $encoded->encryption,
            encryptionKeyId: $encoded->encryptionKeyId,
            encryptionHeader: $encoded->encryptionHeader,
            associatedData: $associatedData
        );
    }

    private function stubbedCodec(string $keySeed = 'A'): FileCodec
    {
        return new FileCodec(keyProvider: $this->createStubConfig(type: KeyProvider::class)
            ->addMethodReturns(name: 'getCurrentKeyId', return: 'main')
            ->addMethodReturns(name: 'getKey', return: str_repeat(string: $keySeed, times: 32))
            ->getSealedStub());
    }

    /**
     * @return array{EncodedContent, resource} encoded facts + rewound encoded stream
     */
    private function encodeToTemp(
        FileCodec $codec,
        string $content,
        AutoCompression|Compression $compression,
        Encryption $encryption,
        string $associatedData = ''
    ): array {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $target = fopen(filename: 'php://temp', mode: 'r+b');

        $this->assertIsResource($source);
        $this->assertIsResource($target);

        fwrite(stream: $source, data: $content);
        rewind(stream: $source);

        $encoded = $codec->encode(
            source: $source,
            target: $target,
            compression: $compression,
            encryption: $encryption,
            associatedData: $associatedData
        );

        fseek(stream: $target, offset: 0);

        return [$encoded, $target];
    }
}
