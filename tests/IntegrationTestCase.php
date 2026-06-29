<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\Storage;
use Etel\SqliteFileStorage\StorageFactory;
use Etel\SqliteFileStorage\StorageOptions;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;

use function bin2hex;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

abstract class IntegrationTestCase extends TestCase
{
    /** @var list<Storage> */
    private array $openedStorages = [];

    private ?string $workDir = null;

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->openedStorages as $storage) {
            try {
                $storage->close();
            } catch (Throwable) {
                // already closed
            }
        }

        $this->openedStorages = [];

        if ($this->workDir !== null && is_dir(filename: $this->workDir)) {
            foreach (glob(pattern: $this->workDir . '/*') ?: [] as $file) {
                @unlink(filename: $file);
            }

            @rmdir(directory: $this->workDir);
            $this->workDir = null;
        }
    }

    protected function databasePath(string $name = 'storage.db'): string
    {
        if ($this->workDir === null) {
            $this->workDir = sys_get_temp_dir() . '/etel-sfs-' . bin2hex(string: random_bytes(length: 6));
            mkdir(directory: $this->workDir);
        }

        return $this->workDir . '/' . $name;
    }

    protected function openStorage(?StorageOptions $options = null, string $name = 'storage.db'): Storage
    {
        $storage = new StorageFactory()->open(
            path: $this->databasePath(name: $name),
            options: $options ?? new StorageOptions(),
        );

        $this->openedStorages[] = $storage;

        return $storage;
    }

    protected function encryptionKey(string $seed = 'A'): string
    {
        return str_repeat(string: $seed, times: 32);
    }

    protected function keyProvider(): ArrayKeyProvider
    {
        return new ArrayKeyProvider(keys: ['main' => $this->encryptionKey()], currentKeyId: 'main');
    }
}
