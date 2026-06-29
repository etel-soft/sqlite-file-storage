<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\StorageIoException;

/**
 * Maps the Encryption enum to compact integers stored in the database.
 *
 * @internal
 */
final readonly class EncryptionMap
{
    public static function fromDomain(Encryption $encryption): int
    {
        return match ($encryption) {
            Encryption::None => 0,
            Encryption::XChaCha20Poly1305 => 1
        };
    }

    public static function toDomain(int $value): Encryption
    {
        return match ($value) {
            0 => Encryption::None,
            1 => Encryption::XChaCha20Poly1305,
            default => throw StorageIoException::unknownStorageValue(field: 'encryption', value: $value)
        };
    }
}
