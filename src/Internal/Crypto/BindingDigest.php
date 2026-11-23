<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

use SensitiveParameter;

use const SODIUM_CRYPTO_GENERICHASH_BYTES;

use function bin2hex;
use function sodium_crypto_generichash;
use function sodium_crypto_kdf_derive_from_key;

/**
 * What a record keeps of the associated data its content is bound to: a MAC under a key derived from the content key,
 * never the data itself nor a bare hash of it.
 *
 * Associated data is often guessable (an owner and a name): a bare hash in the database would give it away to a
 * dictionary, and equal hashes would link objects across keys. Keyed, the digest says nothing to a reader without
 * the key, and is the same only for the same data under the same key — which is what deduplication compares.
 *
 * The digest only makes a refusal clear and early; what binds the content is the associated data given to the cipher.
 *
 * @internal
 */
final readonly class BindingDigest
{
    /** libsodium KDF context: 8 bytes naming what the subkey is for */
    private const string CONTEXT = 'sfs-bind';

    private const int SUBKEY_ID = 1;

    /**
     * @param string $key The content key (32 bytes)
     *
     * @return string Hex of the keyed BLAKE2b digest
     */
    public static function of(#[SensitiveParameter] string $key, string $associatedData): string
    {
        $subkey = sodium_crypto_kdf_derive_from_key(
            subkey_length: SODIUM_CRYPTO_GENERICHASH_BYTES,
            subkey_id: self::SUBKEY_ID,
            context: self::CONTEXT,
            key: $key
        );

        return bin2hex(string: sodium_crypto_generichash(
            message: $associatedData,
            key: $subkey,
            length: SODIUM_CRYPTO_GENERICHASH_BYTES
        ));
    }
}
