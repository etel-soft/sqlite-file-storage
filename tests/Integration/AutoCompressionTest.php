<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\AutoCompression;
use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function random_bytes;
use function str_repeat;

/**
 * An AutoCompression through the storage: each object stores, and reports, the compression it got.
 *
 * @internal
 */
final class AutoCompressionTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('As the storage default, incompressible content is stored as it is and text compressed')]
    public function testAutoAsDefault(): void
    {
        $storage = $this->openStorage(options: new StorageOptions(
            defaultCompression: new AutoCompression(),
            keyProvider: $this->keyProvider()
        ));
        $noise = random_bytes(length: 1_200_000);
        $text = str_repeat(string: 'text to compress, ', times: 80_000);

        $raw = $storage->putObject(key: 'noise.bin', content: $noise);
        $compressed = $storage->putObject(
            key: 'text.txt',
            content: $text,
            options: new PutOptions(encryption: Encryption::XChaCha20Poly1305)
        );

        $this->assertSame(Compression::None, $raw->compression);
        $this->assertSame(1_200_000, $raw->storedSize);
        $this->assertSame(Compression::Zlib, $compressed->compression);
        $this->assertLessThan($compressed->size / 10, $compressed->storedSize);
        $this->assertSame($noise, $storage->getObject(key: 'noise.bin')->getContents());
        $this->assertSame($text, $storage->getObject(key: 'text.txt')->getContents());
    }

    #[Test]
    #[TestDox('Per write, with the algorithm and thresholds of the request')]
    public function testAutoPerWrite(): void
    {
        $storage = $this->openStorage();
        $text = str_repeat(string: 'text to compress, ', times: 1000);

        $info = $storage->putObject(key: 'a.txt', content: $text, options: new PutOptions(
            compression: new AutoCompression(algorithm: Compression::Gzip)
        ));

        $this->assertSame(Compression::Gzip, $info->compression);
        $this->assertSame(Compression::Gzip, $storage->headObject(key: 'a.txt')->compression);
        $this->assertSame($text, $storage->getObject(key: 'a.txt')->getContents());
    }
}
