<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use Throwable;

use function sprintf;

final class StorageIoException extends StorageException
{
    public static function blobOpenFailed(): self
    {
        return new self(message: 'Failed to open content BLOB.');
    }

    public static function cannotOpenDatabase(string $path, Throwable $previous): self
    {
        return new self(
            message: sprintf('Cannot open storage database "%s": %s.', $path, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function codecFailed(string $codec): self
    {
        return new self(message: sprintf('Compression codec "%s" failed to process data.', $codec));
    }

    public static function codecInitFailed(string $codec): self
    {
        return new self(message: sprintf('Compression codec "%s" failed to initialize.', $codec));
    }

    public static function databaseNotFound(string $path): self
    {
        return new self(message: sprintf('Storage database "%s" does not exist.', $path));
    }

    public static function fromSqlite(Throwable $previous): self
    {
        return new self(
            message: sprintf('SQLite error: %s.', $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function queryFailed(): self
    {
        return new self(message: 'SQLite query failed.');
    }

    public static function readFailed(): self
    {
        return new self(message: 'Failed to read from stream.');
    }

    public static function schemaTooNew(int $version): self
    {
        return new self(message: sprintf(
            'Storage schema version %d is newer than supported by this library version.',
            $version,
        ));
    }

    public static function streamOpenFailed(): self
    {
        return new self(message: 'Failed to open stream.');
    }

    public static function unexpectedRowValue(string $field): self
    {
        return new self(message: sprintf('Unexpected value type in stored row field "%s".', $field));
    }

    public static function unknownStorageValue(string $field, int $value): self
    {
        return new self(message: sprintf('Unknown stored value %d for "%s".', $value, $field));
    }

    public static function writeFailed(): self
    {
        return new self(message: 'Failed to write to stream.');
    }
}
