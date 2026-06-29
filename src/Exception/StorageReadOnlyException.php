<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class StorageReadOnlyException extends StorageException
{
    public static function forOperation(string $operation): self
    {
        return new self(message: sprintf('Cannot perform "%s": storage is opened read-only.', $operation));
    }
}
