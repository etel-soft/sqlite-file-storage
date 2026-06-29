<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

/**
 * Incremental (streaming) compressor.
 *
 * @internal
 */
interface Compressor
{
    public function add(string $chunk): string;

    public function finish(): string;
}
