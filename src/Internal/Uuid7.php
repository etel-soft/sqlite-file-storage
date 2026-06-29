<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use const STR_PAD_LEFT;

use function bin2hex;
use function dechex;
use function microtime;
use function ord;
use function random_bytes;
use function sprintf;
use function str_pad;
use function substr;

/**
 * Minimal UUID v7 generator (RFC 9562), avoids any external uuid dependency.
 *
 * @internal
 */
final readonly class Uuid7
{
    public static function generate(): string
    {
        $milliseconds = (int) (microtime(as_float: true) * 1000);
        $random = random_bytes(length: 10);

        $hex = str_pad(string: dechex(num: $milliseconds), length: 12, pad_string: '0', pad_type: STR_PAD_LEFT)
            . bin2hex(string: $random);

        $hex[12] = '7';
        $hex[16] = ['8', '9', 'a', 'b'][ord(character: $random[2]) & 0x03];

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr(string: $hex, offset: 0, length: 8),
            substr(string: $hex, offset: 8, length: 4),
            substr(string: $hex, offset: 12, length: 4),
            substr(string: $hex, offset: 16, length: 4),
            substr(string: $hex, offset: 20, length: 12)
        );
    }
}
