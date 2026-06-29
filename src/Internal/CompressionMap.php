<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\StorageIoException;

/**
 * Maps the Compression enum to compact integers stored in the database.
 *
 * @internal
 */
final readonly class CompressionMap
{
    public static function fromDomain(Compression $compression): int
    {
        return match ($compression) {
            Compression::None => 0,
            Compression::Gzip => 1,
            Compression::Deflate => 2,
            Compression::Zlib => 3,
            Compression::Brotli => 4
        };
    }

    public static function toDomain(int $value): Compression
    {
        return match ($value) {
            0 => Compression::None,
            1 => Compression::Gzip,
            2 => Compression::Deflate,
            3 => Compression::Zlib,
            4 => Compression::Brotli,
            default => throw StorageIoException::unknownStorageValue(field: 'compression', value: $value)
        };
    }
}
