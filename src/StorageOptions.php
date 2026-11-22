<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Options applied when opening a storage node.
 */
final readonly class StorageOptions
{
    /** The write-ahead log a write connection lets stay behind: 64 MiB */
    public const int DEFAULT_JOURNAL_SIZE_LIMIT = 67_108_864;

    /**
     * @param int $journalSizeLimitBytes The size a write connection cuts the write-ahead log back to when it starts
     *                                   it over (PRAGMA journal_size_limit): without it the log keeps the size of the
     *                                   largest write ever made. -1 leaves it as SQLite does, unbounded
     */
    public function __construct(
        public Compression $defaultCompression = Compression::Zlib,
        public Encryption $defaultEncryption = Encryption::None,
        public ?KeyProvider $keyProvider = null,
        public bool $createIfNotExists = true,
        public int $busyTimeoutMs = 5000,
        public bool $readOnly = false,
        public Durability $durability = Durability::Buffered,
        public int $journalSizeLimitBytes = self::DEFAULT_JOURNAL_SIZE_LIMIT
    ) {}
}
