<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Override;

use const BROTLI_FINISH;
use const BROTLI_PROCESS;

use function brotli_compress_add;
use function brotli_compress_init;

/**
 * Incremental Brotli compressor (requires ext-brotli).
 *
 * @internal
 */
final readonly class BrotliCompressor implements Compressor
{
    private const int QUALITY = 6;

    private mixed $context;

    public function __construct()
    {
        // positional argument: parameter names of the third-party extension are not guaranteed
        $context = brotli_compress_init(self::QUALITY);

        if ($context === false) {
            throw StorageIoException::codecInitFailed(codec: 'brotli'); // @codeCoverageIgnore
        }

        $this->context = $context;
    }

    #[Override]
    public function add(string $chunk): string
    {
        $output = brotli_compress_add($this->context, $chunk, BROTLI_PROCESS);

        if ($output === false) {
            throw StorageIoException::codecFailed(codec: 'brotli'); // @codeCoverageIgnore
        }

        return $output;
    }

    #[Override]
    public function finish(): string
    {
        $output = brotli_compress_add($this->context, '', BROTLI_FINISH);

        if ($output === false) {
            throw StorageIoException::codecFailed(codec: 'brotli'); // @codeCoverageIgnore
        }

        return $output;
    }
}
