<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\Codec\Decompressor;
use Etel\SqliteFileStorage\Internal\Crypto\Decryptor;

use function fclose;
use function fopen;
use function fread;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function min;
use function stream_context_create;
use function stream_context_get_options;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function substr;

/**
 * Read-only stream wrapper exposing decrypted and decompressed object content.
 *
 * Wraps the raw SQLite BLOB stream and pulls it through the decryptor/decompressor
 * chunk by chunk on demand, so content is never materialized in memory.
 *
 * @internal
 */
final class DecodingStreamWrapper
{
    public const string PROTOCOL = 'etel-sfs';

    private const int READ_CHUNK_SIZE = 262144;

    /** @var null|resource set by PHP runtime */
    public $context;

    private string $buffer = '';

    private int $bytesConsumed = 0;

    /** Size of one ciphertext chunk; 0 disables chunk framing (unencrypted content). */
    private int $cipherChunkSize = 0;

    private Decompressor $decompressor;

    private Decryptor $decryptor;

    private bool $finished = false;

    private int $plainSize = 0;

    private int $position = 0;

    /** @var resource */
    private $source;

    private int $storedSize = 0;

    /**
     * Opens a decoding stream over the raw BLOB stream.
     *
     * @param resource $source
     *
     * @return resource
     */
    public static function open(
        mixed $source,
        Decryptor $decryptor,
        Decompressor $decompressor,
        int $cipherChunkSize,
        int $storedSize,
        int $plainSize
    ) {
        if (!in_array(needle: self::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(protocol: self::PROTOCOL, class: self::class);
        }

        $context = stream_context_create(options: [self::PROTOCOL => [
            'source' => $source,
            'decryptor' => $decryptor,
            'decompressor' => $decompressor,
            'cipherChunkSize' => $cipherChunkSize,
            'storedSize' => $storedSize,
            'plainSize' => $plainSize,
        ]]);

        $stream = fopen(filename: self::PROTOCOL . '://object', mode: 'rb', context: $context);

        if ($stream === false) {
            throw StorageIoException::streamOpenFailed(); // @codeCoverageIgnore
        }

        return $stream;
    }

    public function stream_close(): void
    {
        if (is_resource(value: $this->source)) {
            fclose(stream: $this->source);
        }
    }

    public function stream_eof(): bool
    {
        return $this->finished && $this->buffer === '';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($this->context === null) {
            return false; // @codeCoverageIgnore
        }

        $contextOptions = stream_context_get_options(stream_or_context: $this->context)[self::PROTOCOL] ?? null;

        if (
            !is_array(value: $contextOptions)
            || !is_resource(value: $contextOptions['source'] ?? null)
            || !($contextOptions['decryptor'] ?? null) instanceof Decryptor
            || !($contextOptions['decompressor'] ?? null) instanceof Decompressor
            || !is_int(value: $contextOptions['cipherChunkSize'] ?? null)
            || !is_int(value: $contextOptions['storedSize'] ?? null)
            || !is_int(value: $contextOptions['plainSize'] ?? null)
        ) {
            return false;
        }

        $this->source = $contextOptions['source'];
        $this->decryptor = $contextOptions['decryptor'];
        $this->decompressor = $contextOptions['decompressor'];
        $this->cipherChunkSize = $contextOptions['cipherChunkSize'];
        $this->storedSize = $contextOptions['storedSize'];
        $this->plainSize = $contextOptions['plainSize'];

        return true;
    }

    public function stream_read(int $count): string
    {
        while (strlen(string: $this->buffer) < $count && !$this->finished) {
            $this->fill();
        }

        $output = substr(string: $this->buffer, offset: 0, length: $count);
        $this->buffer = substr(string: $this->buffer, offset: strlen(string: $output));
        $this->position += strlen(string: $output);

        return $output;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->plainSize];
    }

    // @codeCoverageIgnoreStart
    public function stream_tell(): int
    {
        return $this->position;
    }
    // @codeCoverageIgnoreEnd

    private function fill(): void
    {
        $remaining = $this->storedSize - $this->bytesConsumed;

        if ($remaining <= 0) {
            $this->buffer .= $this->decompressor->finish();
            $this->finished = true;

            return;
        }

        $length = min($this->cipherChunkSize > 0 ? $this->cipherChunkSize : self::READ_CHUNK_SIZE, $remaining);
        $ciphertext = $this->readExactly(length: $length);
        $this->bytesConsumed += strlen(string: $ciphertext);
        $final = $this->bytesConsumed >= $this->storedSize;

        $this->buffer .= $this->decompressor->add(chunk: $this->decryptor->decryptChunk(
            ciphertext: $ciphertext,
            final: $final,
        ));

        if ($final) {
            $this->buffer .= $this->decompressor->finish();
            $this->finished = true;
        }
    }

    private function readExactly(int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $part = fread(stream: $this->source, length: $remaining);

            if ($part === false || $part === '') {
                throw StorageIoException::readFailed(); // @codeCoverageIgnore
            }

            $data .= $part;
            $remaining = $length - strlen(string: $data);
        }

        return $data;
    }
}
