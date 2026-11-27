<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\Codec\Decompressor;
use Etel\SqliteFileStorage\Internal\Codec\PassthroughCodec;
use Etel\SqliteFileStorage\Internal\Crypto\Decryptor;

use const SEEK_END;
use const SEEK_SET;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function max;
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
 * A decompressor gives back all that its input holds, and a little input may hold a lot (a megabyte of zeros is
 * a kilobyte of deflate, and far less of brotli). So a chunk goes to it by slices, sized by what the last ones gave:
 * small while the content expands a lot, larger while it does not — but a kilobyte at most, as the content may
 * change at once: zeros after random bytes. Deflate expands at most 1032 times, so a slice gives a megabyte at
 * most, whatever came before it. Brotli, which can describe 16 MiB in a few bytes, may give that much for a single
 * byte, and more for a kilobyte of such: content that turns from barely to hugely compressible is held decoded
 * that far.
 *
 * The stream seeks (fseek) over a seekable source. Content stored as it is — neither compressed nor encrypted — seeks
 * at once: a position in it is one in the source. Other content is decoded up to the target, from where the stream
 * is or, for a target behind it, from its start again: a secretstream chunk opens only with the state the chunks
 * before it left, and a compressed stream only from its first byte. A seek back never fails for that, as a failed
 * seek costs more than time: PHP drops the bytes it read ahead, and the stream would go on from past them.
 *
 * The content must decode to its recorded size, byte for byte: content that ends before it (a stored file cut short,
 * a compressed stream without its end) or goes on past it fails, instead of being read as if it were whole. Encrypted
 * content is held by its final tag already; this is what holds content that is not.
 *
 * Encrypted content is never stored as nothing: even an empty plaintext is one final chunk, a tag with no bytes under
 * it. A record of encrypted content with no stored bytes has no tag to check, so it fails instead of reading as empty.
 *
 * @internal
 */
final class DecodingStreamWrapper
{
    public const string PROTOCOL = 'etel-sfs';

    /** A slice that gave less than this is followed by one twice as large */
    private const int EXPANSION_LOW = 262144;

    /** A slice that gave more than this is followed by one half as large */
    private const int EXPANSION_HIGH = 524288;

    private const int READ_CHUNK_SIZE = 262144;

    /** The first slice: small, as nothing is known yet of how much the content expands */
    private const int SLICE_INITIAL = 1;

    /** The largest slice: a megabyte of deflate output at most */
    private const int SLICE_MAX = 1024;

    /** @var null|resource Set by PHP runtime */
    public $context;

    /** Decoded bytes not read yet, from $bufferOffset on */
    private string $buffer = '';

    private int $bufferOffset = 0;

    private int $bytesConsumed = 0;

    /** The decrypted chunk being decompressed; given to the decompressor up to $chunkOffset */
    private string $chunk = '';

    private int $chunkOffset = 0;

    /** Size of one ciphertext chunk; 0 disables chunk framing (unencrypted content). */
    private int $cipherChunkSize = 0;

    /** Bytes decoded so far, from the start of the content */
    private int $decoded = 0;

    private Decompressor $decompressor;

    private Decryptor $decryptor;

    private bool $finished = false;

    private int $plainSize = 0;

    private int $position = 0;

    /** Whether the content is stored as it is: a position in it is one in the source, from $sourceStart on */
    private bool $raw = false;

    private int $slice = self::SLICE_INITIAL;

    /** @var resource */
    private $source;

    /** Where the stored content starts in the source */
    private int $sourceStart = 0;

    /** Whether the last stored chunk has been read and decrypted */
    private bool $sourceDrained = false;

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
        return $this->finished && $this->bufferOffset >= strlen(string: $this->buffer);
    }

    /* @noinspection PhpUnusedParameterInspection */
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

        $start = ftell(stream: $this->source);
        $this->sourceStart = $start === false ? 0 : $start;
        $this->raw = $this->cipherChunkSize === 0 && $this->decompressor instanceof PassthroughCodec;

        return true;
    }

    public function stream_read(int $count): string
    {
        while (strlen(string: $this->buffer) - $this->bufferOffset < $count && !$this->finished) {
            $this->fill();
        }

        $output = substr(string: $this->buffer, offset: $this->bufferOffset, length: $count);
        $this->bufferOffset += strlen(string: $output);
        $this->position += strlen(string: $output);

        if ($this->bufferOffset >= strlen(string: $this->buffer)) {
            $this->buffer = '';
            $this->bufferOffset = 0;
        }

        return $output;
    }

    /**
     * Moves to a position of the decoded content; one past its end is its end.
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        // PHP turns SEEK_CUR into SEEK_SET from the position it keeps itself before a wrapper sees it
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_END => $this->plainSize + $offset,
            default => -1,
        };

        if ($target < 0) {
            return false;
        }

        $target = min($target, $this->plainSize);

        if ($this->raw) {
            return $this->resumeAt(sourceOffset: $target);
        }

        if ($target < $this->position) {
            if (!$this->resumeAt(sourceOffset: 0)) {
                return false;
            }

            $this->decryptor = $this->decryptor->restarted();
            $this->decompressor = $this->decompressor->restarted();
            $this->slice = self::SLICE_INITIAL;
        }

        while ($this->position < $target) {
            // content that ends before the target fails in the read, as shorter than its recorded size: nothing
            // read is no way out of here, but it must not be a way to loop forever either
            if ($this->stream_read(count: min(self::READ_CHUNK_SIZE, $target - $this->position)) === '') {
                return false; // @codeCoverageIgnore
            }
        }

        return true;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->plainSize];
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * Adds decoded bytes to the buffer: what the next slice of the current chunk decompresses to (possibly nothing),
     * or the decompressor's tail once the content is through.
     */
    private function fill(): void
    {
        if ($this->chunkOffset >= strlen(string: $this->chunk)) {
            if ($this->sourceDrained) {
                $this->append(decoded: $this->decompressor->finish());
                $this->finished = true;

                if ($this->decoded !== $this->plainSize) {
                    throw ContentIntegrityException::detected(reason: 'content is shorter than its recorded size');
                }

                return;
            }

            $this->chunk = $this->nextChunk();
            $this->chunkOffset = 0;

            return;
        }

        $slice = substr(string: $this->chunk, offset: $this->chunkOffset, length: $this->slice);
        $this->chunkOffset += strlen(string: $slice);
        $decoded = $this->decompressor->add(chunk: $slice);

        if (strlen(string: $decoded) > self::EXPANSION_HIGH) {
            $this->slice = max(1, $this->slice >> 1);
        } elseif (strlen(string: $decoded) < self::EXPANSION_LOW) {
            $this->slice = min(self::SLICE_MAX, $this->slice << 1);
        }

        $this->append(decoded: $decoded);
    }

    private function append(string $decoded): void
    {
        if ($decoded === '') {
            return;
        }

        $this->decoded += strlen(string: $decoded);

        if ($this->decoded > $this->plainSize) {
            throw ContentIntegrityException::detected(reason: 'content is longer than its recorded size');
        }

        // what was read already is dropped here, once per fill, not on every read
        $this->buffer = $this->bufferOffset === 0
            ? $this->buffer . $decoded
            : substr(string: $this->buffer, offset: $this->bufferOffset) . $decoded;
        $this->bufferOffset = 0;
    }

    /**
     * Reading goes on from a place in the stored content, with nothing decoded held: for raw content that is the
     * decoded position too, for any other it is its start.
     */
    private function resumeAt(int $sourceOffset): bool
    {
        if (fseek(stream: $this->source, offset: $this->sourceStart + $sourceOffset) !== 0) {
            return false;
        }

        $this->buffer = '';
        $this->bufferOffset = 0;
        $this->chunk = '';
        $this->chunkOffset = 0;
        $this->bytesConsumed = $sourceOffset;
        $this->position = $sourceOffset;
        $this->decoded = $sourceOffset;
        $this->sourceDrained = $sourceOffset >= $this->storedSize;
        $this->finished = false;

        return true;
    }

    /**
     * The next stored chunk, decrypted; empty when there is none left.
     */
    private function nextChunk(): string
    {
        $remaining = $this->storedSize - $this->bytesConsumed;

        if ($remaining <= 0) {
            if ($this->cipherChunkSize > 0) {
                // the only way here for encrypted content: nothing is stored, so no chunk was ever authenticated
                throw ContentIntegrityException::detected(
                    reason: 'encrypted content is empty: the final chunk that authenticates it is missing'
                );
            }

            $this->sourceDrained = true;

            return '';
        }

        $length = min($this->cipherChunkSize > 0 ? $this->cipherChunkSize : self::READ_CHUNK_SIZE, $remaining);
        $ciphertext = $this->readExactly(length: $length);
        $this->bytesConsumed += strlen(string: $ciphertext);
        $this->sourceDrained = $this->bytesConsumed >= $this->storedSize;

        return $this->decryptor->decryptChunk(ciphertext: $ciphertext, final: $this->sourceDrained);
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
