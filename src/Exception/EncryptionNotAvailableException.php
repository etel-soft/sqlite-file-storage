<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Exception;

use Etel\SqliteFileStorage\Encryption;

use function sprintf;

final class EncryptionNotAvailableException extends StorageException
{
    public static function forAlgorithm(Encryption $encryption): self
    {
        return new self(message: sprintf(
            'Encryption algorithm "%s" is not available: ext-sodium is not loaded.',
            $encryption->value,
        ));
    }
}
