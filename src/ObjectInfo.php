<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use DateTimeImmutable;

/**
 * Object metadata (without content).
 */
final readonly class ObjectInfo
{
    /**
     * @param string               $hash       sha256 hex of the original (uncompressed, unencrypted) content;
     *                                         acts as ETag
     * @param int                  $size       Original content size in bytes
     * @param int                  $storedSize Size of the stored BLOB after compression and encryption
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $name,
        public ?string $mimeType,
        public string $hash,
        public int $size,
        public int $storedSize,
        public Compression $compression,
        public Encryption $encryption,
        public array $meta,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt
    ) {}
}
