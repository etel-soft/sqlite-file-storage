<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\ObjectAlreadyExistsException;
use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;

/**
 * Single SQLite-backed storage node (analogue of an S3 bucket).
 *
 * Object identity is the key (a flat, S3-like path string). Content is processed in chunks:
 * whole files are never materialized in memory.
 */
interface Storage
{
    /**
     * Writes an object.
     *
     * @param mixed $content resource|string|\Stringable
     *
     * @throws ObjectAlreadyExistsException When WriteMode::CreateOnly and the key exists
     * @throws StorageReadOnlyException
     */
    public function putObject(string $key, mixed $content, PutOptions $options = new PutOptions()): ObjectInfo;

    /**
     * Returns the object metadata together with a streaming content body.
     *
     * @throws ObjectNotFoundException
     */
    public function getObject(string $key): StorageObject;

    /**
     * Returns object metadata only, without opening the content BLOB.
     *
     * @throws ObjectNotFoundException
     */
    public function headObject(string $key): ObjectInfo;

    public function hasObject(string $key): bool;

    /**
     * Finds any object whose content matches the given sha256 hex hash.
     */
    public function findByHash(string $hash): ?ObjectInfo;

    /**
     * Lists objects ordered by key, lazily, in pages of $limit.
     *
     * @return iterable<ObjectInfo>
     */
    public function listObjects(string $prefix = '', ?string $afterKey = null, int $limit = 1000): iterable;

    /**
     * Copies an object; the content BLOB is shared (refcount), not duplicated.
     * An existing target key is overwritten.
     *
     * @throws ObjectNotFoundException
     */
    public function copyObject(string $sourceKey, string $targetKey): ObjectInfo;

    /**
     * Renames an object. An existing target key is overwritten.
     *
     * @throws ObjectNotFoundException
     */
    public function moveObject(string $sourceKey, string $targetKey): ObjectInfo;

    /**
     * Replaces object metadata (and optionally the mime type) without rewriting content.
     *
     * @param array<string, null|scalar> $meta
     *
     * @throws ObjectNotFoundException
     */
    public function updateObjectMeta(string $key, array $meta, ?string $mimeType = null): ObjectInfo;

    /**
     * @throws ObjectNotFoundException
     */
    public function deleteObject(string $key): void;

    public function getStats(): StorageStats;

    /**
     * Creates the schema for a new database or migrates an older schema version.
     */
    public function initialize(): void;

    /**
     * Rebuilds the database file, reclaiming space left by deleted objects.
     */
    public function vacuum(): void;

    public function close(): void;
}
