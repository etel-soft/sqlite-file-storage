<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class InvalidEncryptionKeyException extends StorageException
{
    public static function byKeyId(string $keyId, int $expectedBytes): self
    {
        return new self(message: sprintf(
            'Encryption key "%s" has invalid length: %d raw bytes expected.',
            $keyId,
            $expectedBytes,
        ));
    }
}
