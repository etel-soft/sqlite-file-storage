<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Exception;
use SQLite3;

use const SQLITE3_OPEN_CREATE;
use const SQLITE3_OPEN_READONLY;
use const SQLITE3_OPEN_READWRITE;

use function file_exists;

/**
 * Opens (and, unless disabled, creates) SQLite storage nodes.
 */
final class StorageFactory
{
    public function open(string $path, StorageOptions $options = new StorageOptions()): Storage
    {
        $exists = file_exists(filename: $path);

        if (!$exists && ($options->readOnly || !$options->createIfNotExists)) {
            throw StorageIoException::databaseNotFound(path: $path);
        }

        try {
            $db = new SQLite3(
                filename: $path,
                flags: $options->readOnly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
            );
        } catch (Exception $exception) {
            throw StorageIoException::cannotOpenDatabase(path: $path, previous: $exception);
        }

        $db->enableExceptions(enable: true);
        $db->busyTimeout(milliseconds: $options->busyTimeoutMs);

        if (!$options->readOnly) {
            // auto_vacuum must be configured before the first table is created.
            $db->exec(query: 'PRAGMA auto_vacuum = INCREMENTAL');
            $db->exec(query: 'PRAGMA journal_mode = WAL');
            $db->exec(query: 'PRAGMA synchronous = NORMAL');
        }

        $storage = new SqliteStorage(db: $db, options: $options);

        if ($options->readOnly) {
            $storage->verifyInitialized(path: $path);
        } else {
            $storage->initialize();
        }

        return $storage;
    }
}
