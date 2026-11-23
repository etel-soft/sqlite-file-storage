<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

/**
 * Per-write options for Storage::putObject().
 */
final readonly class PutOptions
{
    /**
     * @param null|AutoCompression|Compression $compression    An algorithm, or a request to decide per object;
     *                                                         null => storage default
     * @param null|Encryption                  $encryption     null => storage default
     * @param null|string                      $id             Externally supplied uuid; null => generated (uuid
     *                                                         v7); ignored when an existing key is overwritten
     * @param null|string                      $name           Original file name; null => basename of the key
     * @param null|string                      $mimeType       null => detected from leading bytes via finfo
     * @param array<string, null|scalar>       $meta
     * @param bool                             $deduplicate    Reuse identical stored content (matched by hash +
     *                                                         encryption + key id + associated data)
     * @param string                           $associatedData What encrypted content is bound to (say, its owner
     *                                                         and its name): Storage::getObject() needs the same
     *                                                         bytes to read it. Needs encryption; empty binds to
     *                                                         nothing
     */
    public function __construct(
        public AutoCompression|Compression|null $compression = null,
        public ?Encryption $encryption = null,
        public WriteMode $writeMode = WriteMode::Overwrite,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $mimeType = null,
        public array $meta = [],
        public bool $deduplicate = true,
        public string $associatedData = ''
    ) {}
}
