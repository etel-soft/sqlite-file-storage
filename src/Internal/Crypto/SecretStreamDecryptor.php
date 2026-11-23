<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Override;
use SensitiveParameter;
use SodiumException;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

use function is_int;
use function is_string;
use function sodium_crypto_secretstream_xchacha20poly1305_init_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_pull;

/**
 * XChaCha20-Poly1305 secretstream decryptor (libsodium).
 *
 * The associated data is checked with the first chunk, as the encryptor authenticated it.
 *
 * @internal
 */
final class SecretStreamDecryptor implements Decryptor
{
    private string $associatedData;

    private readonly string $initialAssociatedData;

    private readonly string $initialState;

    private string $state;

    public function __construct(
        #[SensitiveParameter]
        string $key,
        string $header,
        string $associatedData = '',
    ) {
        $this->associatedData = $associatedData;
        $this->initialAssociatedData = $associatedData;

        try {
            $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(header: $header, key: $key);
            $this->initialState = $this->state;
        } catch (SodiumException $exception) {
            throw ContentIntegrityException::detected(reason: 'invalid encryption header', previous: $exception);
        }
    }

    /* @noinspection PhpRedundantCatchClauseInspection */
    #[Override]
    public function decryptChunk(string $ciphertext, bool $final): string
    {
        try {
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
                state: $this->state,
                ciphertext: $ciphertext,
                additional_data: $this->associatedData
            );
            // @codeCoverageIgnoreStart
        } catch (SodiumException $exception) {
            throw ContentIntegrityException::detected(reason: 'chunk decryption failed', previous: $exception);
        }
        // @codeCoverageIgnoreEnd

        if ($result === false) {
            throw ContentIntegrityException::detected(
                reason: 'chunk authentication failed (wrong key, wrong associated data or corrupted data)'
            );
        }

        $this->associatedData = '';
        [$plaintext, $tag] = $result;

        if (!is_string(value: $plaintext) || !is_int(value: $tag)) {
            throw ContentIntegrityException::detected(reason: 'unexpected decryption result'); // @codeCoverageIgnore
        }

        if ($final !== ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)) {
            throw ContentIntegrityException::detected(reason: 'encrypted stream is truncated or has trailing data');
        }

        return $plaintext;
    }

    #[Override]
    public function restarted(): self
    {
        $restarted = clone $this;
        $restarted->state = $this->initialState;
        $restarted->associatedData = $this->initialAssociatedData;

        return $restarted;
    }
}
