<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Internal;

use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use Etel\SqliteFileStorage\Internal\Crypto\Encryptor;
use Generator;

use function feof;
use function fread;
use function fwrite;
use function hash_final;
use function hash_update;
use function max;
use function strlen;
use function substr;

/**
 * Pulls content through hashing, compression and chunked encryption into a target stream, a chunk at a time.
 *
 * For an AutoCompression request the start of the content is read first — held in memory,
 * up to the sample size — to choose the compression; the rest streams as before.
 *
 * @internal
 */
final readonly class ContentEncoder
{
    /** Plaintext size of one encrypted chunk, and of one read from the source */
    public const int CHUNK_SIZE = 262144;

    /** The largest chunk a reader takes from a record: a chunk is held in memory before it is authenticated */
    public const int MAX_CHUNK_SIZE = 16_777_216;

    /**
     * @param resource    $source
     * @param resource    $target
     * @param ContentHash $hash   What the content is hashed with: a bare SHA-256 unless a hash key is set
     */
    public function encode(
        mixed $source,
        mixed $target,
        AutoCompression|Compression $compression,
        Encryptor $encryptor,
        ContentHash $hash = new ContentHash()
    ): EncodingOutcome {
        $head = '';
        $mimeType = false;

        if ($compression instanceof AutoCompression) {
            $head = $this->readHead(source: $source, length: $compression->sampleBytes);
            $mimeType = Mime::detect(leadingBytes: substr(string: $head, offset: 0, length: Mime::SNIFF_SIZE));
            $compression = $compression->choose(sample: $head, mimeType: $mimeType);
        }

        $compressor = CodecFactory::compressor(compression: $compression);
        $hashContext = $hash->start();
        $size = 0;
        $storedSize = 0;
        $leadingBytes = '';
        $pending = '';

        foreach ($this->chunks(source: $source, head: $head) as $chunk) {
            if (strlen(string: $leadingBytes) < Mime::SNIFF_SIZE) {
                $leadingBytes .= substr(
                    string: $chunk,
                    offset: 0,
                    length: Mime::SNIFF_SIZE - strlen(string: $leadingBytes)
                );
            }

            hash_update(context: $hashContext, data: $chunk);
            $size += strlen(string: $chunk);
            $pending .= $compressor->add(chunk: $chunk);
            $storedSize += $this->flush(target: $target, encryptor: $encryptor, pending: $pending, keepLast: false);
        }

        $pending .= $compressor->finish();
        $storedSize += $this->flush(target: $target, encryptor: $encryptor, pending: $pending, keepLast: true);
        $storedSize += $this->write(target: $target, data: $encryptor->encryptChunk(plaintext: $pending, final: true));

        return new EncodingOutcome(
            hash: hash_final(context: $hashContext),
            size: $size,
            storedSize: $storedSize,
            mimeType: $mimeType === false ? Mime::detect(leadingBytes: $leadingBytes) : $mimeType,
            compression: $compression
        );
    }

    /**
     * The held head first, then the rest of the source, chunk by chunk.
     *
     * @param resource $source
     *
     * @return Generator<string>
     */
    private function chunks(mixed $source, string $head): Generator
    {
        if ($head !== '') {
            yield $head;
        }

        while (!feof(stream: $source)) {
            $chunk = fread(stream: $source, length: self::CHUNK_SIZE);

            if ($chunk === false) {
                throw StorageIoException::readFailed(); // @codeCoverageIgnore
            }

            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * Encrypts and writes the whole chunks gathered; the last one is kept back while it may be the final one
     * (a full last chunk while the compressor still has bytes to give, or any remainder at the end).
     *
     * @param resource $target
     */
    private function flush(mixed $target, Encryptor $encryptor, string &$pending, bool $keepLast): int
    {
        $written = 0;
        $whole = $keepLast ? self::CHUNK_SIZE + 1 : self::CHUNK_SIZE;

        while (strlen(string: $pending) >= $whole) {
            /* @noinspection PhpPipeOperatorCanBeUsedInspection */
            $written += $this->write(target: $target, data: $encryptor->encryptChunk(
                plaintext: substr(string: $pending, offset: 0, length: self::CHUNK_SIZE),
                final: false
            ));
            $pending = substr(string: $pending, offset: self::CHUNK_SIZE);
        }

        return $written;
    }

    /**
     * @param resource $source
     */
    private function readHead(mixed $source, int $length): string
    {
        $head = '';

        while (strlen(string: $head) < $length && !feof(stream: $source)) {
            $part = fread(stream: $source, length: max(1, $length - strlen(string: $head)));

            if ($part === false) {
                throw StorageIoException::readFailed(); // @codeCoverageIgnore
            }

            $head .= $part;
        }

        return $head;
    }

    /**
     * @param resource $target
     */
    private function write(mixed $target, string $data): int
    {
        if ($data === '') {
            return 0;
        }

        if (fwrite(stream: $target, data: $data) !== strlen(string: $data)) {
            throw StorageIoException::writeFailed(); // @codeCoverageIgnore
        }

        return strlen(string: $data);
    }
}
