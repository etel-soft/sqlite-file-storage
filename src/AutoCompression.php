<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use InvalidArgumentException;

use function count;
use function intdiv;
use function min;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * A request to decide, per object, whether its content is worth compressing — as `compression=on` does
 * in ZFS: content already compressed (JPEG, MP4, ZIP and the like) is stored as it is, so writing it costs no CPU
 * for nothing, and a reader can seek in it instead of decoding it from its first byte (in a stream position,
 * when the content is not encrypted either; forward only, by decrypting, when it is).
 *
 * Two steps, on the first `sampleBytes` of the content, read before anything is written:
 *  - its type, detected from the bytes (never from what a client claims): a known compressed format is stored
 *    without compression at once — FLAC, whose leading tags and padding would compress, is caught here;
 *  - otherwise a probe: `windows` stretches of `windowBytes`, spread across the sample (the first at its start,
 *    the last at its end), are compressed one by one. The content is compressed when the median of their ratios is
 *    below `maxRatio`: a header unlike the rest (zeros of padding, a thumbnail before text) moves the median less
 *    than it would a sum.
 *
 * A wrong guess costs space or CPU only: the codec stored with the content is the one applied.
 */
final readonly class AutoCompression
{
    /**
     * Content types that are compressed already. An entry ending in "/" or "." covers every type it starts.
     */
    public const array COMPRESSED_TYPES = [
        'video/',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/jxl',
        'audio/flac',
        'audio/x-flac',
        'audio/mpeg',
        'audio/ogg',
        'audio/opus',
        'audio/aac',
        'audio/x-hx-aac-adts',
        'audio/mp4',
        'audio/x-m4a',
        'font/woff',
        'font/woff2',
        'application/zip',
        'application/gzip',
        'application/x-gzip',
        'application/x-bzip2',
        'application/x-xz',
        'application/x-lzma',
        'application/x-lzip',
        'application/x-lz4',
        'application/zstd',
        'application/x-zstd',
        'application/x-7z-compressed',
        'application/x-rar',
        'application/vnd.rar',
        'application/x-rar-compressed',
        'application/java-archive',
        'application/epub+zip',
        'application/vnd.android.package-archive',
        'application/vnd.openxmlformats-officedocument.',
        'application/vnd.oasis.opendocument.',
    ];

    /** The most of the content held in memory to decide: 16 MiB */
    public const int MAX_SAMPLE_BYTES = 16_777_216;

    /**
     * @param Compression  $algorithm       What compressible content is compressed with
     * @param float        $maxRatio        Compressed / original size of the probe at which the content is stored as
     *                                      it is: the saving below it is not worth the CPU on every read
     * @param int          $sampleBytes     How much of the content's start is read (and held in memory, on every
     *                                      write) to decide; at most MAX_SAMPLE_BYTES
     * @param int          $windowBytes     Size of one probed stretch
     * @param int          $windows         How many stretches are probed across the sample
     * @param list<string> $compressedTypes Content types stored without compression, without a probe
     *
     * @throws InvalidArgumentException When the algorithm is not one, or a size is out of range
     */
    public function __construct(
        public Compression $algorithm = Compression::Zlib,
        public float $maxRatio = 0.95,
        public int $sampleBytes = 1_048_576,
        public int $windowBytes = 65_536,
        public int $windows = 4,
        public array $compressedTypes = self::COMPRESSED_TYPES
    ) {
        if ($algorithm === Compression::None) {
            throw new InvalidArgumentException(message: 'Auto compression needs an algorithm to compress with.');
        }

        // in this form NAN fails too: every comparison with it is false
        if (!($maxRatio > 0.0 && $maxRatio <= 1.0)) {
            throw new InvalidArgumentException(message: 'The ratio of auto compression must be in (0, 1].');
        }

        if ($windowBytes < 1 || $windows < 1 || $sampleBytes < $windowBytes) {
            throw new InvalidArgumentException(
                message: 'Auto compression probes at least one window of at least one byte, within its sample.'
            );
        }

        if ($sampleBytes > self::MAX_SAMPLE_BYTES) {
            throw new InvalidArgumentException(
                message: 'An auto compression sample is held in memory: 16 MiB at most.'
            );
        }
    }

    /**
     * The compression to apply to content that starts with the sample.
     *
     * @param string      $sample   The content's first bytes, up to `sampleBytes` (all of it when shorter)
     * @param null|string $mimeType Its type as detected from its bytes; null when undetected
     */
    public function choose(string $sample, ?string $mimeType): Compression
    {
        if ($sample === '' || ($mimeType !== null && $this->compressed(mimeType: $mimeType))) {
            return Compression::None;
        }

        return $this->medianRatio(sample: $sample) < $this->maxRatio ? $this->algorithm : Compression::None;
    }

    private function compressed(string $mimeType): bool
    {
        foreach ($this->compressedTypes as $type) {
            $prefix = str_ends_with(haystack: $type, needle: '/') || str_ends_with(haystack: $type, needle: '.');

            if ($prefix ? str_starts_with(haystack: $mimeType, needle: $type) : $mimeType === $type) {
                return true;
            }
        }

        return false;
    }

    private function medianRatio(string $sample): float
    {
        $length = strlen(string: $sample);
        $window = min($length, $this->windowBytes);
        $count = $length <= $this->windowBytes ? 1 : $this->windows;
        $ratios = [];

        for ($index = 0; $index < $count; ++$index) {
            $offset = $count === 1 ? 0 : intdiv(num1: $index * ($length - $window), num2: $count - 1);
            $stretch = substr(string: $sample, offset: $offset, length: $window);
            $compressor = CodecFactory::compressor(compression: $this->algorithm);
            $compressed = $compressor->add(chunk: $stretch) . $compressor->finish();
            $ratios[] = strlen(string: $compressed) / $window;
        }

        sort(array: $ratios);
        $middle = intdiv(num1: count(value: $ratios), num2: 2);

        return count(value: $ratios) % 2 === 1 ? $ratios[$middle] : ($ratios[$middle - 1] + $ratios[$middle]) / 2;
    }
}
