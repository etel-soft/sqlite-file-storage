<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Per-write options for Storage::putObject().
 */
final readonly class PutOptions
{
    /**
     * @param null|Compression           $compression null => storage default
     * @param null|Encryption            $encryption  null => storage default
     * @param null|string                $id          Externally supplied uuid; null => generated (uuid v7);
     *                                                ignored when an existing key is overwritten
     * @param null|string                $name        Original file name; null => basename of the key
     * @param null|string                $mimeType    null => detected from leading bytes via finfo
     * @param array<string, null|scalar> $meta
     * @param bool                       $deduplicate Reuse identical stored content
     *                                                (matched by hash + encryption + key id)
     */
    public function __construct(
        public ?Compression $compression = null,
        public ?Encryption $encryption = null,
        public WriteMode $writeMode = WriteMode::Overwrite,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $mimeType = null,
        public array $meta = [],
        public bool $deduplicate = true
    ) {}
}
