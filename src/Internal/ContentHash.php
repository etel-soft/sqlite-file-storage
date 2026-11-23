<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\KeyProvider;
use HashContext;
use SensitiveParameter;

use const HASH_HMAC;

use function fclose;
use function feof;
use function fread;
use function hash_final;
use function hash_hmac;
use function hash_init;
use function hash_update;
use function strlen;

/**
 * The hash a record keeps of its original content: what deduplication and findByHash() match, and the ETag.
 *
 * Without a key it is the SHA-256 of the content, as it always was. A reader of the database can then tell whether
 * it holds a file they know, by hashing the file. With a key it is an HMAC-SHA256, which says nothing to a reader
 * without the key; it is still the same for the same content, so deduplication works as before.
 *
 * The HMAC key is derived from the one given, not the one itself: a key also used for something else gives nothing
 * about that use away here.
 *
 * @internal
 */
final readonly class ContentHash
{
    /** Raw bytes of the key a key provider gives */
    public const int KEY_BYTES = 32;

    private const int READ_SIZE = 262144;

    /** Names what the derived key is for */
    private const string PURPOSE = 'etel-sfs content hash v1';

    /** The derived HMAC key; null for a bare SHA-256 */
    private ?string $key;

    /**
     * @param null|string $key The hash key (32 bytes); null for a bare SHA-256
     */
    public function __construct(#[SensitiveParameter] ?string $key = null)
    {
        $this->key = $key === null ? null : hash_hmac(algo: 'sha256', data: self::PURPOSE, key: $key, binary: true);
    }

    /**
     * The hash under the key of the given id, or the bare one when no id is set.
     *
     * @throws EncryptionKeyNotFoundException When a key is asked for and there is no provider, or no such key
     * @throws InvalidEncryptionKeyException  When the key is not 32 bytes
     */
    public static function resolve(?KeyProvider $keyProvider, ?string $keyId): self
    {
        if ($keyId === null) {
            return new self();
        }

        $key = ($keyProvider ?? throw EncryptionKeyNotFoundException::noKeyProviderForHashKey())->getKey(keyId: $keyId);

        if (strlen(string: $key) !== self::KEY_BYTES) {
            throw InvalidEncryptionKeyException::byKeyId(keyId: $keyId, expectedBytes: self::KEY_BYTES);
        }

        return new self(key: $key);
    }

    /**
     * The hash of the content given to putObject(): a stream is read from where it is to its end.
     *
     * @param mixed $content resource|string|\Stringable
     *
     * @return string 64 hex characters
     */
    public function of(mixed $content): string
    {
        $source = Streams::toResource(content: $content);
        $context = $this->start();

        try {
            while (!feof(stream: $source)) {
                $chunk = fread(stream: $source, length: self::READ_SIZE);

                if ($chunk === false) {
                    throw StorageIoException::readFailed(); // @codeCoverageIgnore
                }

                hash_update(context: $context, data: $chunk);
            }
        } finally {
            if ($source !== $content) {
                fclose(stream: $source);
            }
        }

        return hash_final(context: $context);
    }

    /**
     * A context to feed the content to; hash_final() of it gives the hash, 64 hex characters.
     */
    public function start(): HashContext
    {
        return $this->key === null
            ? hash_init(algo: 'sha256')
            : hash_init(algo: 'sha256', flags: HASH_HMAC, key: $this->key);
    }
}
