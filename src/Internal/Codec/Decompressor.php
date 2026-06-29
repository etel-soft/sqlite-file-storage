<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

/**
 * Incremental (streaming) decompressor.
 *
 * @internal
 */
interface Decompressor
{
    public function add(string $chunk): string;

    public function finish(): string;
}
