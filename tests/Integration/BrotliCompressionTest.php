<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\CompressionNotAvailableException;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function extension_loaded;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function hash;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function random_bytes;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * @internal
 */
final class BrotliCompressionTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Round-trips compressible content through brotli with and without encryption')]
    #[RequiresPhpExtension('brotli')]
    public function testCompressibleRoundtrip(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
        $content = str_repeat(string: 'Compressible brotli payload spanning multiple chunks. ', times: 25000);

        foreach ([Encryption::None, Encryption::XChaCha20Poly1305] as $i => $encryption) {
            $key = 'brotli/' . $i;
            $info = $storage->putObject(key: $key, content: $content, options: new PutOptions(
                compression: Compression::Brotli,
                encryption: $encryption
            ));

            $this->assertSame(Compression::Brotli, $info->compression);
            $this->assertSame(strlen(string: $content), $info->size);
            $this->assertLessThan($info->size, $info->storedSize);
            $this->assertSame($content, $storage->getObject(key: $key)->getContents());
        }
    }

    #[Test]
    #[TestDox('Reads content brotli shrank to almost nothing without holding it whole')]
    #[RequiresPhpExtension('brotli')]
    public function testHighlyCompressedContentIsReadInBoundedMemory(): void
    {
        $storage = $this->openStorage();
        $size = 64 * 1024 * 1024;
        $source = fopen(filename: 'php://temp/maxmemory:1024', mode: 'r+b');
        $this->assertIsResource($source);

        for ($written = 0; $written < $size; $written += 1024 * 1024) {
            fwrite(stream: $source, data: str_repeat(string: "\0", times: 1024 * 1024));
        }

        rewind(stream: $source);
        $info = $storage->putObject(key: 'zeros', content: $source, options: new PutOptions(
            compression: Compression::Brotli
        ));
        fclose(stream: $source);

        $stream = $storage->getObject(key: 'zeros')->getContentStream();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $read = 0;

        while (!feof(stream: $stream)) {
            $read += strlen(string: (string) fread(stream: $stream, length: 8192));
        }

        $this->assertSame($size, $info->size);
        $this->assertSame($size, $read);
        // brotli describes up to 16 MiB in a few bytes: that much comes from the smallest slice, and no more
        $this->assertLessThan($size / 2, memory_get_peak_usage() - $before);
    }

    #[Test]
    #[TestDox('Round-trips incompressible content through brotli without corruption')]
    #[RequiresPhpExtension('brotli')]
    public function testIncompressibleRoundtrip(): void
    {
        $storage = $this->openStorage();
        $content = random_bytes(length: 700000);

        $info = $storage->putObject(key: 'random.bin', content: $content, options: new PutOptions(
            compression: Compression::Brotli
        ));

        $this->assertSame(hash(algo: 'sha256', data: $content), $info->hash);
        $this->assertSame($content, $storage->getObject(key: 'random.bin')->getContents());
    }

    #[Test]
    #[TestDox('Throws when brotli is requested but the extension is unavailable')]
    public function testBrotliWithoutExtensionThrows(): void
    {
        if (extension_loaded(extension: 'brotli')) {
            $this->markTestSkipped('ext-brotli is loaded; the unavailability path cannot be exercised.');
        }

        $storage = $this->openStorage();

        $this->expectException(CompressionNotAvailableException::class);

        $storage->putObject(key: 'doc.txt', content: 'data', options: new PutOptions(compression: Compression::Brotli));
    }
}
