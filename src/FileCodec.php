<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\EncryptionNotAvailableException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use Etel\SqliteFileStorage\Internal\Crypto\Decryptor;
use Etel\SqliteFileStorage\Internal\Crypto\Encryptor;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamDecryptor;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamEncryptor;
use Etel\SqliteFileStorage\Internal\DecodingStreamWrapper;
use finfo;

use const FILEINFO_MIME_TYPE;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

use function base64_decode;
use function base64_encode;
use function class_exists;
use function feof;
use function fread;
use function function_exists;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function strlen;
use function substr;

/**
 * Streaming compression/encryption for content stored OUTSIDE sqlite volumes
 * (e.g. large objects kept as plain files): the same codecs and chunked AEAD
 * framing the storage engine uses internally, applied to arbitrary streams.
 */
final readonly class FileCodec
{
    public const int CHUNK_SIZE = 262144;

    private const int MIME_SNIFF_SIZE = 65536;

    public function __construct(private ?KeyProvider $keyProvider = null) {}

    /**
     * Reads the source stream and writes the encoded bytes to the target stream.
     *
     * @param resource $source
     * @param resource $target
     */
    public function encode(
        mixed $source,
        mixed $target,
        Compression $compression,
        Encryption $encryption
    ): EncodedContent {
        [$encryptor, $keyId] = $this->createEncryptor(encryption: $encryption);
        $compressor = CodecFactory::compressor(compression: $compression);

        $hashContext = hash_init(algo: 'sha256');
        $size = 0;
        $storedSize = 0;
        $firstBytes = '';
        $pending = '';

        while (!feof(stream: $source)) {
            $chunk = fread(stream: $source, length: self::CHUNK_SIZE);

            if ($chunk === false) {
                throw StorageIoException::readFailed(); // @codeCoverageIgnore
            }

            if ($chunk === '') {
                continue;
            }

            if (strlen(string: $firstBytes) < self::MIME_SNIFF_SIZE) {
                $firstBytes .= substr(
                    string: $chunk,
                    offset: 0,
                    length: self::MIME_SNIFF_SIZE - strlen(string: $firstBytes)
                );
            }

            hash_update(context: $hashContext, data: $chunk);
            $size += strlen(string: $chunk);
            $pending .= $compressor->add(chunk: $chunk);

            while (strlen(string: $pending) >= self::CHUNK_SIZE) {
                $storedSize += self::write(target: $target, data: $encryptor->encryptChunk(
                    plaintext: substr(string: $pending, offset: 0, length: self::CHUNK_SIZE),
                    final: false
                ));
                $pending = substr(string: $pending, offset: self::CHUNK_SIZE);
            }
        }

        $pending .= $compressor->finish();

        while (strlen(string: $pending) > self::CHUNK_SIZE) {
            // @codeCoverageIgnoreStart
            $storedSize += self::write(target: $target, data: $encryptor->encryptChunk(
                plaintext: substr(string: $pending, offset: 0, length: self::CHUNK_SIZE),
                final: false
            ));
            $pending = substr(string: $pending, offset: self::CHUNK_SIZE);
            // @codeCoverageIgnoreEnd
        }

        $storedSize += self::write(target: $target, data: $encryptor->encryptChunk(plaintext: $pending, final: true));

        return new EncodedContent(
            hash: hash_final(context: $hashContext),
            size: $size,
            storedSize: $storedSize,
            mimeType: self::detectMime(leadingBytes: $firstBytes),
            compression: $compression,
            encryption: $encryption,
            encryptionKeyId: $keyId,
            encryptionHeader: $encryption === Encryption::None ? null : base64_encode(string: $encryptor->header()),
            chunkSize: self::CHUNK_SIZE
        );
    }

    /**
     * Returns a lazy stream of the decoded content.
     *
     * @param resource $source Stream over the encoded bytes
     *
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
        int $chunkSize = self::CHUNK_SIZE
    ) {
        $decryptor = new PassthroughCrypto();
        $cipherChunkSize = 0;

        if ($encryption !== Encryption::None) {
            $decryptor = $this->createDecryptor(
                encryption: $encryption,
                encryptionKeyId: $encryptionKeyId,
                encryptionHeader: $encryptionHeader
            );
            $cipherChunkSize = $chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
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
        ?string $encryptionHeader
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

        return new SecretStreamDecryptor(key: $key, header: $header);
    }

    /**
     * @return array{Encryptor, null|string}
     */
    private function createEncryptor(Encryption $encryption): array
    {
        if ($encryption === Encryption::None) {
            return [new PassthroughCrypto(), null];
        }

        self::assertEncryptionAvailable(encryption: $encryption);

        $provider = $this->requireKeyProvider();
        $keyId = $provider->getCurrentKeyId();
        $key = $provider->getKey(keyId: $keyId);

        self::assertKeyLength(key: $key, keyId: $keyId);

        return [new SecretStreamEncryptor(key: $key), $keyId];
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

    private static function detectMime(string $leadingBytes): ?string
    {
        if ($leadingBytes === '' || !class_exists(class: finfo::class)) {
            return null;
        }

        $mime = new finfo(flags: FILEINFO_MIME_TYPE)->buffer(string: $leadingBytes);

        return $mime === false ? null : $mime;
    }

    /**
     * @param resource $target
     */
    private static function write(mixed $target, string $data): int
    {
        if ($data === '') {
            return 0;
        }

        if (fwrite(stream: $target, data: $data) !== strlen(string: $data)) {
            throw StorageIoException::writeFailed(); // @codeCoverageIgnore
        }

        return strlen(string: $data);
    }
}
