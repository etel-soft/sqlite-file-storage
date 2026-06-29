<?php

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
 * @internal
 */
final class SecretStreamEncryptor implements Encryptor
{
    private readonly string $header;

    private string $state;

    public function __construct(
        #[SensitiveParameter]
        string $key,
    ) {
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
        return sodium_crypto_secretstream_xchacha20poly1305_push(
            state: $this->state,
            message: $plaintext,
            tag: $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
        );
    }

    #[Override]
    public function header(): string
    {
        return $this->header;
    }
}
