<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const NAN;

use function random_bytes;
use function str_repeat;

/**
 * @internal
 */
#[CoversClass(AutoCompression::class)]
final class AutoCompressionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Defaults: zlib below a ratio of 0.95, probed in four 64 KiB windows across the first 1 MiB')]
    public function testDefaults(): void
    {
        $auto = new AutoCompression();

        $this->assertSame(Compression::Zlib, $auto->algorithm);
        $this->assertSame(0.95, $auto->maxRatio);
        $this->assertSame(1_048_576, $auto->sampleBytes);
        $this->assertSame(65_536, $auto->windowBytes);
        $this->assertSame(4, $auto->windows);
        $this->assertSame(AutoCompression::COMPRESSED_TYPES, $auto->compressedTypes);
        $this->assertSame(16_777_216, AutoCompression::MAX_SAMPLE_BYTES);
        $this->assertSame(
            AutoCompression::MAX_SAMPLE_BYTES,
            new AutoCompression(sampleBytes: AutoCompression::MAX_SAMPLE_BYTES)->sampleBytes
        );
    }

    #[Test]
    #[TestDox('Settings out of range are refused: $_dataName')]
    #[DataProvider('provideInvalidSettings')]
    public function testInvalidSettingsAreRefused(
        Compression $algorithm,
        float $maxRatio,
        int $sampleBytes,
        int $windowBytes,
        int $windows
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new AutoCompression(
            algorithm: $algorithm,
            maxRatio: $maxRatio,
            sampleBytes: $sampleBytes,
            windowBytes: $windowBytes,
            windows: $windows
        );
    }

    /**
     * @return iterable<string, array{Compression, float, int, int, int}>
     */
    public static function provideInvalidSettings(): iterable
    {
        yield 'none is no algorithm' => [Compression::None, 0.95, 1024, 512, 4];

        yield 'a ratio of zero' => [Compression::Zlib, 0.0, 1024, 512, 4];

        yield 'a ratio above one' => [Compression::Zlib, 1.01, 1024, 512, 4];

        yield 'a ratio that is no number' => [Compression::Zlib, NAN, 1024, 512, 4];

        yield 'an empty window' => [Compression::Zlib, 0.95, 1024, 0, 4];

        yield 'no windows' => [Compression::Zlib, 0.95, 1024, 512, 0];

        yield 'a sample smaller than a window' => [Compression::Zlib, 0.95, 511, 512, 4];

        yield 'a sample above 16 MiB' => [Compression::Zlib, 0.95, AutoCompression::MAX_SAMPLE_BYTES + 1, 512, 4];
    }

    #[Test]
    #[TestDox('Empty content is stored as it is')]
    public function testEmptyContentIsNotCompressed(): void
    {
        $this->assertSame(Compression::None, new AutoCompression()->choose(sample: '', mimeType: null));
    }

    #[Test]
    #[TestDox('A known compressed type is stored as it is without a probe, even when its bytes would compress')]
    #[DataProvider('provideCompressedTypes')]
    public function testCompressedTypeIsNotCompressed(string $mimeType): void
    {
        $text = str_repeat(string: 'compressible ', times: 10_000);

        $this->assertSame(
            Compression::None,
            new AutoCompression()->choose(sample: $text, mimeType: $mimeType)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCompressedTypes(): iterable
    {
        yield 'exact' => ['audio/flac'];

        yield 'under a type prefix' => ['video/mp4'];

        yield 'under a name prefix' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    #[Test]
    #[TestDox('Text, of no known type or a type not listed, is compressed')]
    public function testCompressibleContentIsCompressed(): void
    {
        $auto = new AutoCompression();
        $text = str_repeat(string: 'compressible ', times: 100_000);

        $this->assertSame(Compression::Zlib, $auto->choose(sample: $text, mimeType: null));
        $this->assertSame(Compression::Zlib, $auto->choose(sample: $text, mimeType: 'text/plain'));
        $this->assertSame(Compression::Zlib, $auto->choose(sample: $text, mimeType: 'videos/x-not-a-video'));
    }

    #[Test]
    #[TestDox('Random bytes are stored as they are, whether longer or shorter than one window')]
    public function testIncompressibleContentIsNotCompressed(): void
    {
        $auto = new AutoCompression();

        /* @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertSame(
            Compression::None,
            $auto->choose(sample: random_bytes(length: 1_048_576), mimeType: null)
        );
        /* @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertSame(
            Compression::None,
            $auto->choose(sample: random_bytes(length: 4096), mimeType: null)
        );
    }

    #[Test]
    #[TestDox('Short text, under one window, is probed whole and compressed with the chosen algorithm')]
    public function testShortContentIsProbedWhole(): void
    {
        $auto = new AutoCompression(algorithm: Compression::Gzip);

        $text = str_repeat(string: 'ab', times: 500);

        $this->assertSame(Compression::Gzip, $auto->choose(sample: $text, mimeType: null));
    }

    #[Test]
    #[TestDox('A header unlike the rest does not decide: the median of the windows does')]
    public function testHeaderDoesNotOutweighTheRest(): void
    {
        $auto = new AutoCompression();
        $paddedNoise = str_repeat(string: "\0", times: 65_536) . random_bytes(length: 983_040);
        $thumbnailThenText = random_bytes(length: 65_536) . str_repeat(string: 'text ', times: 196_608);

        $this->assertSame(Compression::None, $auto->choose(sample: $paddedNoise, mimeType: null));
        $this->assertSame(Compression::Zlib, $auto->choose(sample: $thumbnailThenText, mimeType: null));
    }

    #[Test]
    #[TestDox('With an odd number of windows the median is the middle one')]
    public function testOddWindowCount(): void
    {
        $auto = new AutoCompression(sampleBytes: 3072, windowBytes: 1024, windows: 3);
        $mostlyNoise = random_bytes(length: 2048) . str_repeat(string: 'a', times: 1024);
        $mostlyText = str_repeat(string: 'a', times: 2048) . random_bytes(length: 1024);

        $this->assertSame(Compression::None, $auto->choose(sample: $mostlyNoise, mimeType: null));
        $this->assertSame(Compression::Zlib, $auto->choose(sample: $mostlyText, mimeType: null));
    }

    #[Test]
    #[TestDox('The ratio is the threshold: content compressing just under it is stored as it is at a stricter one')]
    public function testRatioIsTheThreshold(): void
    {
        $halfNoise = random_bytes(length: 32_768) . str_repeat(string: 'a', times: 32_768);

        $this->assertSame(
            Compression::Zlib,
            new AutoCompression()->choose(sample: $halfNoise, mimeType: null)
        );
        $this->assertSame(
            Compression::None,
            new AutoCompression(maxRatio: 0.3)->choose(sample: $halfNoise, mimeType: null)
        );
    }
}
