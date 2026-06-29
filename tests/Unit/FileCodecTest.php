<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\EncodedContent;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\FileCodec;
use Etel\SqliteFileStorage\KeyProvider;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

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
        Compression $compression,
        Encryption $encryption
    ): array {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $target = fopen(filename: 'php://temp', mode: 'r+b');

        $this->assertIsResource($source);
        $this->assertIsResource($target);

        fwrite(stream: $source, data: $content);
        rewind(stream: $source);

        $encoded = $codec->encode(source: $source, target: $target, compression: $compression, encryption: $encryption);

        fseek(stream: $target, offset: 0);

        return [$encoded, $target];
    }
}
