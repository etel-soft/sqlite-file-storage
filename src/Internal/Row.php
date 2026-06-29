<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Exception\StorageIoException;

use function is_int;
use function is_string;

/**
 * Typed access to SQLite result row values.
 *
 * @internal
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;

        if (is_int(value: $value)) {
            return $value;
        }

        throw StorageIoException::unexpectedRowValue(field: $field);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function intOrNull(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;

        if ($value === null || is_int(value: $value)) {
            return $value;
        }

        throw StorageIoException::unexpectedRowValue(field: $field);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (is_string(value: $value)) {
            return $value;
        }

        throw StorageIoException::unexpectedRowValue(field: $field);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function stringOrNull(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        if ($value === null || is_string(value: $value)) {
            return $value;
        }

        throw StorageIoException::unexpectedRowValue(field: $field);
    }
}
