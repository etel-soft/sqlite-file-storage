<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Compression;

/**
 * What ContentEncoder wrote.
 *
 * @internal
 */
final readonly class EncodingOutcome
{
    public function __construct(
        /** sha256 hex of the original content, or its HMAC-SHA256 under a hash key */
        public string $hash,
        /** Original content size in bytes */
        public int $size,
        /** Bytes written to the target */
        public int $storedSize,
        /** Detected from the leading original bytes; null when undetectable */
        public ?string $mimeType,
        /** The compression applied: the requested one, or what an AutoCompression chose */
        public Compression $compression
    ) {}
}
