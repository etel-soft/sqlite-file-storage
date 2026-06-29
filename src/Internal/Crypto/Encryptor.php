<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

/**
 * Chunked AEAD encryptor.
 *
 * @internal
 */
interface Encryptor
{
    /**
     * Stream header to persist alongside the content (empty for Encryption::None).
     */
    public function header(): string;

    public function encryptChunk(string $plaintext, bool $final): string;
}
