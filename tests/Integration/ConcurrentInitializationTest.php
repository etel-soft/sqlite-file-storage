<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function dirname;
use function fclose;
use function file_put_contents;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function touch;
use function var_export;

/**
 * Several processes opening one new database at the same moment: each of them must find a usable schema.
 *
 * @internal
 */
final class ConcurrentInitializationTest extends IntegrationTestCase
{
    private const int PROCESSES = 8;

    #[Test]
    #[TestDox('Processes opening a new database at once all get an initialized storage')]
    public function testConcurrentOpenOfANewDatabase(): void
    {
        $database = $this->databasePath(name: 'shared.db');
        $start = $this->databasePath(name: 'start');
        $script = $this->databasePath(name: 'open.php');
        file_put_contents(filename: $script, data: $this->openScript(database: $database, start: $start));

        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $pipes = [];
            $process = proc_open(
                command: [PHP_BINARY, $script],
                descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                pipes: $pipes,
            );
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        touch(filename: $start);

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents(stream: $pipes[1]) . stream_get_contents(stream: $pipes[2]);
            fclose(stream: $pipes[1]);
            fclose(stream: $pipes[2]);

            $this->assertSame(0, proc_close(process: $process), $output);
            $this->assertSame('ok', $output);
        }

        $this->assertSame(self::PROCESSES, $this->openStorage(name: 'shared.db')->getStats()->objectCount);
    }

    /* @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
    private function openScript(string $database, string $start): string
    {
        $autoload = var_export(value: dirname(path: __DIR__, levels: 2) . '/vendor/autoload.php', return: true);
        $database = var_export(value: $database, return: true);
        $start = var_export(value: $start, return: true);

        return <<<PHP
            <?php
            require {$autoload};

            \$deadline = microtime(true) + 10;
            while (!file_exists({$start}) && microtime(true) < \$deadline) {
                usleep(1000);
            }

            \$storage = new Etel\\SqliteFileStorage\\StorageFactory()->open(path: {$database});
            \$storage->putObject(key: 'object-' . getmypid(), content: 'data');
            \$storage->close();
            echo 'ok';
            PHP;
    }
}
