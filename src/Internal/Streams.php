<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use InvalidArgumentException;
use Stringable;

use function fopen;
use function fwrite;
use function is_resource;
use function is_string;
use function rewind;
use function strlen;

/**
 * @internal
 */
final readonly class Streams
{
    public const string SPOOL_URI = 'php://temp/maxmemory:2097152';

    /**
     * Normalizes putObject() content input to a readable stream.
     *
     * @return resource
     */
    public static function toResource(mixed $content)
    {
        if (is_resource(value: $content)) {
            return $content;
        }

        if (is_string(value: $content) || $content instanceof Stringable) {
            $content = (string) $content;
            $stream = fopen(filename: self::SPOOL_URI, mode: 'r+b');

            if ($stream === false) {
                throw StorageIoException::streamOpenFailed(); // @codeCoverageIgnore
            }

            if ($content !== '' && fwrite(stream: $stream, data: $content) !== strlen(string: $content)) {
                throw StorageIoException::writeFailed(); // @codeCoverageIgnore
            }

            rewind(stream: $stream);

            return $stream;
        }

        throw new InvalidArgumentException(message: 'Content must be a resource, string or Stringable.');
    }
}
