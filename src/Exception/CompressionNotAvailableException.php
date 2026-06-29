<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use Etel\SqliteFileStorage\Compression;

use function sprintf;

final class CompressionNotAvailableException extends StorageException
{
    public static function forAlgorithm(Compression $compression): self
    {
        return new self(message: sprintf(
            'Compression algorithm "%s" is not available: required PHP extension is not loaded.',
            $compression->value,
        ));
    }
}
