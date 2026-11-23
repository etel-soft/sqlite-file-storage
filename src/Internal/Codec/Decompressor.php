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

    /**
     * A decompressor of the same format, at the start of a stream: reading content again from its first byte.
     */
    public function restarted(): self;
}
