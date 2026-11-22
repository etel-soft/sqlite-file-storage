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
use function max;

/**
 * Opens (and, unless disabled, creates) SQLite storage nodes.
 */
final class StorageFactory
{
    /** PRAGMA auto_vacuum's answer for INCREMENTAL */
    private const int AUTO_VACUUM_INCREMENTAL = 2;

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
                // auto_vacuum must be configured before the first table is created. Setting it takes the write
                // lock even where it changes nothing (a database with tables keeps its mode), so it is set only while
                // the database does not have it yet: opening one already set up waits for no writer
                if ($db->querySingle(query: 'PRAGMA auto_vacuum') !== self::AUTO_VACUUM_INCREMENTAL) {
                    $db->exec(query: 'PRAGMA auto_vacuum = INCREMENTAL');
                }
                $db->exec(query: 'PRAGMA journal_mode = WAL');
                // the log is cut back to this when this connection starts it over: one large write leaves no
                // log of its size behind (a connection's own setting, as synchronous)
                $db->exec(query: 'PRAGMA journal_size_limit = ' . max(-1, $options->journalSizeLimitBytes));
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
