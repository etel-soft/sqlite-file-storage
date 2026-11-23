<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Crypto;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;

/**
 * Chunked AEAD decryptor.
 *
 * @internal
 */
interface Decryptor
{
    /**
     * @throws ContentIntegrityException When chunk authentication fails or the stream is truncated
     */
    public function decryptChunk(string $ciphertext, bool $final): string;

    /**
     * A decryptor of the same stream, before its first chunk: reading content again from its first byte.
     */
    public function restarted(): self;
}
