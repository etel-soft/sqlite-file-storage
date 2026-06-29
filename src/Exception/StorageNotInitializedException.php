<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use function sprintf;

final class StorageNotInitializedException extends StorageException
{
    public static function atPath(string $path): self
    {
        return new self(message: sprintf('Storage database "%s" has no initialized schema.', $path));
    }
}
