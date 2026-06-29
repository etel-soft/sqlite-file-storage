<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;

/**
 * Resolves encryption key material. The library never stores keys, only key identifiers.
 */
interface KeyProvider
{
    /**
     * Identifier of the key used to encrypt new objects (supports key rotation).
     */
    public function getCurrentKeyId(): string;

    /**
     * Raw 32-byte key for the given identifier.
     *
     * @throws EncryptionKeyNotFoundException
     */
    public function getKey(string $keyId): string;
}
