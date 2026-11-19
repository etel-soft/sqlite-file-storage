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
            // SQLite opens any file lazily: one that is no database fails here, on the first statement
            try {
                // auto_vacuum must be configured before the first table is created.
                $db->exec(query: 'PRAGMA auto_vacuum = INCREMENTAL');
                $db->exec(query: 'PRAGMA journal_mode = WAL');
                // whether a commit waits for the disk: applies to this connection only
                $db->exec(query: match ($options->durability) {
                    Durability::Buffered => 'PRAGMA synchronous = NORMAL',
                    Durability::Synced => 'PRAGMA synchronous = FULL',
                });
            } catch (Exception $exception) {
                $db->close();

                throw StorageIoException::cannotOpenDatabase(path: $path, previous: $exception);
            }
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
