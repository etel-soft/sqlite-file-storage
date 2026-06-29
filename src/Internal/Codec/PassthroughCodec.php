<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Override;

/**
 * No-op codec for Compression::None.
 *
 * @internal
 */
final readonly class PassthroughCodec implements Compressor, Decompressor
{
    #[Override]
    public function add(string $chunk): string
    {
        return $chunk;
    }

    #[Override]
    public function finish(): string
    {
        return '';
    }
}
