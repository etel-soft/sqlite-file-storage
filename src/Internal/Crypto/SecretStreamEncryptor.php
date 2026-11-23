<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Override;
use SensitiveParameter;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

use function is_string;
use function sodium_crypto_secretstream_xchacha20poly1305_init_push;
use function sodium_crypto_secretstream_xchacha20poly1305_push;

/**
 * XChaCha20-Poly1305 secretstream encryptor (libsodium).
 *
 * The associated data is authenticated with the first chunk. Each chunk's tag feeds the nonce of the next,
 * so it binds the whole stream: decrypted with other associated data, the first chunk already fails.
 *
 * @internal
 */
final class SecretStreamEncryptor implements Encryptor
{
    private readonly string $header;

    private string $associatedData;

    private string $state;

    public function __construct(
        #[SensitiveParameter]
        string $key,
        string $associatedData = '',
    ) {
        $this->associatedData = $associatedData;

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(key: $key);

        if (!is_string(value: $state) || !is_string(value: $header)) {
            throw StorageIoException::streamOpenFailed(); // @codeCoverageIgnore
        }

        $this->state = $state;
        $this->header = $header;
    }

    #[Override]
    public function encryptChunk(string $plaintext, bool $final): string
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            state: $this->state,
            message: $plaintext,
            additional_data: $this->associatedData,
            tag: $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
        );
        $this->associatedData = '';

        return $ciphertext;
    }

    #[Override]
    public function header(): string
    {
        return $this->header;
    }
}
