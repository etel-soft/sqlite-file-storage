<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

use Override;

/**
 * No-op crypto for Encryption::None.
 *
 * @internal
 */
final readonly class PassthroughCrypto implements Decryptor, Encryptor
{
    #[Override]
    public function decryptChunk(string $ciphertext, bool $final): string
    {
        return $ciphertext;
    }

    #[Override]
    public function encryptChunk(string $plaintext, bool $final): string
    {
        return $plaintext;
    }

    #[Override]
    public function header(): string
    {
        return '';
    }
}
