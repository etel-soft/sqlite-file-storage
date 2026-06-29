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
use function hash;
use function random_bytes;
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
