<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use InflateContext;
use Override;

use const ZLIB_FINISH;
use const ZLIB_NO_FLUSH;

use function inflate_add;
use function inflate_init;

/**
 * Incremental decompressor for the zlib family (raw deflate / gzip / zlib).
 *
 * @internal
 */
final readonly class ZlibDecompressor implements Decompressor
{
    private InflateContext $context;

    /**
     * @param int $encoding one of the ZLIB_ENCODING_* constants
     */
    public function __construct(int $encoding)
    {
        $context = inflate_init(encoding: $encoding);

        if ($context === false) {
            throw StorageIoException::codecInitFailed(codec: 'inflate'); // @codeCoverageIgnore
        }

        $this->context = $context;
    }

    #[Override]
    public function add(string $chunk): string
    {
        $output = @inflate_add(context: $this->context, data: $chunk, flush_mode: ZLIB_NO_FLUSH);

        if ($output === false) {
            throw ContentIntegrityException::detected(reason: 'compressed data is corrupted');
        }

        return $output;
    }

    #[Override]
    public function finish(): string
    {
        $output = @inflate_add(context: $this->context, data: '', flush_mode: ZLIB_FINISH);

        if ($output === false) {
            throw ContentIntegrityException::detected(reason: 'compressed data is truncated'); // @codeCoverageIgnore
        }

        return $output;
    }
}
