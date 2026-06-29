<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Aggregated storage statistics.
 */
final readonly class StorageStats
{
    /**
     * @param int $objectCount     Number of logical objects
     * @param int $contentCount    Number of physical contents (less than objectCount when deduplicated)
     * @param int $totalSize       Sum of original object sizes (logical)
     * @param int $totalStoredSize Sum of stored BLOB sizes (physical)
     */
    public function __construct(
        public int $objectCount,
        public int $contentCount,
        public int $totalSize,
        public int $totalStoredSize
    ) {}
}
