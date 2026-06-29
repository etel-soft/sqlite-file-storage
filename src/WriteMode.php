<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

enum WriteMode
{
    /** Existing key is overwritten (S3 behaviour, default). */
    case Overwrite;

    /** Existing key raises ObjectAlreadyExistsException. */
    case CreateOnly;
}
