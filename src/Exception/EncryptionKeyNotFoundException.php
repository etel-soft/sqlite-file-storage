<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class EncryptionKeyNotFoundException extends StorageException
{
    public static function byKeyId(string $keyId): self
    {
        return new self(message: sprintf('Encryption key "%s" not found.', $keyId));
    }

    public static function noKeyProvider(): self
    {
        return new self(message: 'Encryption is requested but no KeyProvider is configured for the storage.');
    }
}
