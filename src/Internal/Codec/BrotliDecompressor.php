<?php

/* @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal\Codec;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Override;

use const BROTLI_FINISH;
use const BROTLI_PROCESS;

use function brotli_uncompress_add;
use function brotli_uncompress_init;

/**
 * Incremental Brotli decompressor (requires ext-brotli).
 *
 * @internal
 */
final readonly class BrotliDecompressor implements Decompressor
{
    private mixed $context;

    public function __construct()
    {
        $context = brotli_uncompress_init();

        if ($context === false) {
            throw StorageIoException::codecInitFailed(codec: 'brotli'); // @codeCoverageIgnore
        }

        $this->context = $context;
    }

    #[Override]
    public function add(string $chunk): string
    {
        $output = @brotli_uncompress_add($this->context, $chunk, BROTLI_PROCESS);

        if ($output === false) {
            throw ContentIntegrityException::detected(reason: 'compressed data is corrupted');
        }

        return $output;
    }

    #[Override]
    public function finish(): string
    {
        $output = @brotli_uncompress_add($this->context, '', BROTLI_FINISH);

        if ($output === false) {
            throw ContentIntegrityException::detected(reason: 'compressed data is truncated'); // @codeCoverageIgnore
        }

        return $output;
    }

    #[Override]
    public function restarted(): self
    {
        return new self();
    }
}
