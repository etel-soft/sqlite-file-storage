<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class ObjectNotFoundException extends StorageException
{
    public static function byKey(string $key): self
    {
        return new self(message: sprintf('Object with key "%s" not found.', $key));
    }
}
