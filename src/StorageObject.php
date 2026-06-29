<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Exception\StorageIoException;

use function stream_get_contents;

/**
 * Object metadata together with its content stream.
 *
 * The stream is bound to the storage connection: consume it before the storage is closed
 * or the object is overwritten/deleted.
 */
final class StorageObject
{
    /** @var resource */
    private mixed $stream;

    /**
     * @param resource $stream decrypted and decompressed content stream
     */
    public function __construct(public readonly ObjectInfo $info, mixed $stream)
    {
        $this->stream = $stream;
    }

    /**
     * @return resource read-only, non-seekable stream of the original content
     */
    public function getContentStream()
    {
        return $this->stream;
    }

    /**
     * Convenience for small files; reads the whole stream into memory.
     */
    public function getContents(): string
    {
        $contents = stream_get_contents(stream: $this->stream);

        if ($contents === false) {
            throw StorageIoException::readFailed(); // @codeCoverageIgnore
        }

        return $contents;
    }
}
