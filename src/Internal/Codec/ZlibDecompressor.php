<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use InflateContext;
use Override;

use const ZLIB_NO_FLUSH;
use const ZLIB_STREAM_END;

use function inflate_add;
use function inflate_get_status;
use function inflate_init;

/**
 * Incremental decompressor for the zlib family (raw deflate / gzip / zlib).
 *
 * @internal
 */
final class ZlibDecompressor implements Decompressor
{
    private readonly InflateContext $context;

    /** Whether the compressed stream has reached its end */
    private bool $ended = false;

    /**
     * @param int $encoding One of the ZLIB_ENCODING_* constants
     */
    public function __construct(private readonly int $encoding)
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
        if ($chunk === '') {
            return '';
        }

        if ($this->ended) {
            throw ContentIntegrityException::detected(reason: 'data follows the end of the compressed stream');
        }

        $output = @inflate_add(context: $this->context, data: $chunk, flush_mode: ZLIB_NO_FLUSH);

        if ($output === false) {
            throw ContentIntegrityException::detected(reason: 'compressed data is corrupted');
        }

        $this->ended = inflate_get_status(context: $this->context) === ZLIB_STREAM_END;

        return $output;
    }

    #[Override]
    public function finish(): string
    {
        // inflate gives no error for a stream without its end: it waits for more
        if (!$this->ended) {
            throw ContentIntegrityException::detected(reason: 'compressed data is truncated');
        }

        return '';
    }

    #[Override]
    public function restarted(): self
    {
        return new self(encoding: $this->encoding);
    }
}
