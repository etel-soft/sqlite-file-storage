<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use finfo;

use const FILEINFO_MIME_TYPE;

use function class_exists;

/**
 * Content type detected from the content's leading bytes.
 *
 * @internal
 */
final readonly class Mime
{
    /** How many leading bytes the detection looks at */
    public const int SNIFF_SIZE = 65536;

    /**
     * Null when there is nothing to look at, ext-fileinfo is missing or the type is not recognised.
     */
    public static function detect(string $leadingBytes): ?string
    {
        if ($leadingBytes === '' || !class_exists(class: finfo::class)) {
            return null;
        }

        $mime = new finfo(flags: FILEINFO_MIME_TYPE)->buffer(string: $leadingBytes);

        return $mime === false ? null : $mime;
    }
}
