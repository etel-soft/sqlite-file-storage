<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\EncryptionNotAvailableException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use Etel\SqliteFileStorage\Internal\ContentEncoder;
use Etel\SqliteFileStorage\Internal\ContentHash;
use Etel\SqliteFileStorage\Internal\Crypto\Decryptor;
use Etel\SqliteFileStorage\Internal\Crypto\Encryptor;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamDecryptor;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamEncryptor;
use Etel\SqliteFileStorage\Internal\DecodingStreamWrapper;
use InvalidArgumentException;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

use function base64_decode;
use function base64_encode;
use function function_exists;
use function strlen;

/**
 * Streaming compression/encryption for content stored OUTSIDE sqlite volumes (e.g. large objects kept as plain files):
 * the same codecs and chunked AEAD framing the storage engine uses internally, applied to arbitrary streams.
 */
final readonly class FileCodec
{
    public const int CHUNK_SIZE = ContentEncoder::CHUNK_SIZE;

    private ContentEncoder $encoder;

    /**
     * @param null|string $hashKeyId Id of a key of the key provider (32 bytes, one of its own) to hash content with:
     *                               EncodedContent::$hash is then an HMAC-SHA256 and tells nothing about the content
     *                               to who sees it. Null keeps the bare SHA-256. The same rules as
     *                               StorageOptions::$hashKeyId
     */
    public function __construct(private ?KeyProvider $keyProvider = null, private ?string $hashKeyId = null)
    {
        $this->encoder = new ContentEncoder();
    }

    /**
     * The hash encode() gives a content (EncodedContent::$hash), without encoding it: the sha256 hex of the content,
     * or — with a hash key — its HMAC-SHA256.
     *
     * @param mixed $content resource|string|\Stringable; a stream is read from where it is to its end
     */
    public function contentHash(mixed $content): string
    {
        return $this->hasher()->of(content: $content);
    }

    /**
     * Reads the source stream and writes the encoded bytes to the target stream.
     *
     * @param resource                    $source
     * @param resource                    $target
     * @param AutoCompression|Compression $compression    An algorithm, or a request to decide by the content; the
     *                                                    one applied is returned
     * @param string                      $associatedData What encrypted content is bound to (say, its owner and
     *                                                    its name): decoding needs the same bytes, so a replica
     *                                                    moved to another owner or name does not decrypt there.
     *                                                    Empty binds to nothing
     *
     * @throws InvalidArgumentException When associated data is given without encryption, which nothing would hold
     */
    public function encode(
        mixed $source,
        mixed $target,
        AutoCompression|Compression $compression,
        Encryption $encryption,
        string $associatedData = ''
    ): EncodedContent {
        if ($encryption === Encryption::None && $associatedData !== '') {
            throw new InvalidArgumentException(
                message: 'Associated data binds encrypted content only: without encryption nothing would hold it.'
            );
        }

        [$encryptor, $keyId] = $this->createEncryptor(encryption: $encryption, associatedData: $associatedData);
        $outcome = $this->encoder->encode(
            source: $source,
            target: $target,
            compression: $compression,
            encryptor: $encryptor,
            hash: $this->hasher()
        );

        return new EncodedContent(
            hash: $outcome->hash,
            size: $outcome->size,
            storedSize: $outcome->storedSize,
            mimeType: $outcome->mimeType,
            compression: $outcome->compression,
            encryption: $encryption,
            encryptionKeyId: $keyId,
            encryptionHeader: $encryption === Encryption::None ? null : base64_encode(string: $encryptor->header()),
            chunkSize: self::CHUNK_SIZE
        );
    }

    /**
     * Returns a lazy stream of the decoded content. Over a seekable source it seeks: at once in content neither
     * compressed nor encrypted, by decoding up to the target in any other (a seek back decodes from the start again).
     *
     * The stream takes the source over: closing it (or letting it go out of scope) closes the source too.
     *
     * Content that does not decode to exactly $plainSize bytes fails as its end is read.
     *
     * @param resource $source         Stream over the encoded bytes
     * @param string   $associatedData The bytes the content was encoded with ({@see self::encode()})
     *
     * @throws ContentIntegrityException When associated data is given for content that is not encrypted, so not
     *                                   bound to it, or the chunk size is out of range; content bound to other
     *                                   data fails as it is read
     * @return resource
     */
    public function decode(
        mixed $source,
        int $storedSize,
        int $plainSize,
        Compression $compression,
        Encryption $encryption,
        ?string $encryptionKeyId = null,
        ?string $encryptionHeader = null,
        int $chunkSize = self::CHUNK_SIZE,
        string $associatedData = ''
    ) {
        $decryptor = new PassthroughCrypto();
        $cipherChunkSize = 0;

        if ($encryption !== Encryption::None) {
            if ($chunkSize < 1 || $chunkSize > ContentEncoder::MAX_CHUNK_SIZE) {
                throw ContentIntegrityException::detected(reason: 'malformed chunk size');
            }

            $decryptor = $this->createDecryptor(
                encryption: $encryption,
                encryptionKeyId: $encryptionKeyId,
                encryptionHeader: $encryptionHeader,
                associatedData: $associatedData
            );
            $cipherChunkSize = $chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        } elseif ($associatedData !== '') {
            throw ContentIntegrityException::detected(
                reason: 'content is not encrypted, so not bound to the associated data given'
            );
        }

        return DecodingStreamWrapper::open(
            source: $source,
            decryptor: $decryptor,
            decompressor: CodecFactory::decompressor(compression: $compression),
            cipherChunkSize: $cipherChunkSize,
            storedSize: $storedSize,
            plainSize: $plainSize
        );
    }

    private function createDecryptor(
        Encryption $encryption,
        ?string $encryptionKeyId,
        ?string $encryptionHeader,
        string $associatedData
    ): Decryptor {
        self::assertEncryptionAvailable(encryption: $encryption);

        if ($encryptionKeyId === null || $encryptionHeader === null) {
            throw ContentIntegrityException::detected(reason: 'missing encryption context');
        }

        $header = base64_decode(string: $encryptionHeader, strict: true);

        if ($header === false) {
            throw ContentIntegrityException::detected(reason: 'malformed encryption header');
        }

        $key = $this->requireKeyProvider()->getKey(keyId: $encryptionKeyId);

        self::assertKeyLength(key: $key, keyId: $encryptionKeyId);

        return new SecretStreamDecryptor(key: $key, header: $header, associatedData: $associatedData);
    }

    /**
     * @return array{Encryptor, null|string}
     */
    private function createEncryptor(Encryption $encryption, string $associatedData): array
    {
        if ($encryption === Encryption::None) {
            return [new PassthroughCrypto(), null];
        }

        self::assertEncryptionAvailable(encryption: $encryption);

        $provider = $this->requireKeyProvider();
        $keyId = $provider->getCurrentKeyId();
        $key = $provider->getKey(keyId: $keyId);

        self::assertKeyLength(key: $key, keyId: $keyId);

        return [new SecretStreamEncryptor(key: $key, associatedData: $associatedData), $keyId];
    }

    private function hasher(): ContentHash
    {
        return ContentHash::resolve(keyProvider: $this->keyProvider, keyId: $this->hashKeyId);
    }

    private function requireKeyProvider(): KeyProvider
    {
        return $this->keyProvider ?? throw EncryptionKeyNotFoundException::noKeyProvider();
    }

    private static function assertEncryptionAvailable(Encryption $encryption): void
    {
        if (!function_exists(function: 'sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw EncryptionNotAvailableException::forAlgorithm(encryption: $encryption); // @codeCoverageIgnore
        }
    }

    private static function assertKeyLength(string $key, string $keyId): void
    {
        if (strlen(string: $key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw InvalidEncryptionKeyException::byKeyId(
                keyId: $keyId,
                expectedBytes: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES
            );
        }
    }
}
