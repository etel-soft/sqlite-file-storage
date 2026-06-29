<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use DeflateContext;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Override;

use const ZLIB_FINISH;
use const ZLIB_NO_FLUSH;

use function deflate_add;
use function deflate_init;

/**
 * Incremental compressor for the zlib family (raw deflate / gzip / zlib).
 *
 * @internal
 */
final readonly class ZlibCompressor implements Compressor
{
    private const int LEVEL = 6;

    private DeflateContext $context;

    /**
     * @param int $encoding one of the ZLIB_ENCODING_* constants
     */
    public function __construct(int $encoding)
    {
        $context = deflate_init(encoding: $encoding, options: ['level' => self::LEVEL]);

        if ($context === false) {
            throw StorageIoException::codecInitFailed(codec: 'deflate'); // @codeCoverageIgnore
        }

        $this->context = $context;
    }

    #[Override]
    public function add(string $chunk): string
    {
        $output = deflate_add(context: $this->context, data: $chunk, flush_mode: ZLIB_NO_FLUSH);

        if ($output === false) {
            throw StorageIoException::codecFailed(codec: 'deflate'); // @codeCoverageIgnore
        }

        return $output;
    }

    #[Override]
    public function finish(): string
    {
        $output = deflate_add(context: $this->context, data: '', flush_mode: ZLIB_FINISH);

        if ($output === false) {
            throw StorageIoException::codecFailed(codec: 'deflate'); // @codeCoverageIgnore
        }

        return $output;
    }
}
