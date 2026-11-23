<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use JsonSerializable;
use Override;

/**
 * Supported content compression algorithms.
 *
 * Brotli requires the optional ext-brotli extension; the zlib family is always available.
 *
 * To let the writer decide per object whether content is worth compressing, ask for an {@see AutoCompression}
 * instead: what is stored, and reported back, is always one of these — the compression actually applied.
 */
enum Compression: string implements JsonSerializable
{
    case Brotli = 'brotli';
    case Deflate = 'deflate';
    case Gzip = 'gzip';
    case None = 'none';
    case Zlib = 'zlib';

    #[Override]
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
