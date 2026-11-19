<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Options applied when opening a storage node.
 */
final readonly class StorageOptions
{
    public function __construct(
        public Compression $defaultCompression = Compression::Zlib,
        public Encryption $defaultEncryption = Encryption::None,
        public ?KeyProvider $keyProvider = null,
        public bool $createIfNotExists = true,
        public int $busyTimeoutMs = 5000,
        public bool $readOnly = false,
        public Durability $durability = Durability::Buffered
    ) {}
}
