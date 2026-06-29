<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class ObjectAlreadyExistsException extends StorageException
{
    public static function byKey(string $key): self
    {
        return new self(message: sprintf('Object with key "%s" already exists.', $key));
    }
}
