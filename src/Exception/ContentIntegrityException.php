<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use Throwable;

use function sprintf;

final class ContentIntegrityException extends StorageException
{
    public static function detected(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            message: sprintf('Stored content failed integrity check: %s.', $reason),
            previous: $previous,
        );
    }
}
