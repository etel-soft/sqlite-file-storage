<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * How far a write has gone when it returns (SQLite's `synchronous` in WAL mode: FULL, NORMAL).
 */
enum Durability: string
{
    /**
     * Handed to the OS (default): the log is synced only at checkpoints. The storage stays consistent, but a power
     * cut or an OS crash may roll back the last writes, ones that already returned. A process crash loses nothing.
     */
    case Buffered = 'buffered';

    /**
     * On the disk: every commit syncs the write-ahead log, so a write that returned survives a power cut or an OS
     * crash. A sync per write: on a slow disk it bounds the writes a storage takes per second.
     */
    case Synced = 'synced';
}
