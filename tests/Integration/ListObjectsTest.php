<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\ObjectInfo;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function array_map;
use function iterator_to_array;

/**
 * @internal
 */
final class ListObjectsTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Lists objects under a prefix ordered by key')]
    public function testListByPrefixOrderedByKey(): void
    {
        $storage = $this->openStorage();

        foreach (['b/1.txt', 'a/2.txt', 'a/1.txt', 'a/sub/3.txt'] as $key) {
            $storage->putObject(key: $key, content: 'content of ' . $key);
        }

        $this->assertSame(
            ['a/1.txt', 'a/2.txt', 'a/sub/3.txt'],
            $this->keysOf(infos: iterator_to_array(iterator: $storage->listObjects(prefix: 'a/'), preserve_keys: false))
        );

        $this->assertSame(
            ['a/1.txt', 'a/2.txt', 'a/sub/3.txt', 'b/1.txt'],
            $this->keysOf(infos: iterator_to_array(iterator: $storage->listObjects(), preserve_keys: false))
        );
    }

    #[Test]
    #[TestDox('Paginates transparently across the configured page limit')]
    public function testPaginationIsTransparent(): void
    {
        $storage = $this->openStorage();

        for ($i = 1; $i <= 5; ++$i) {
            $storage->putObject(key: 'item/' . $i, content: 'v' . $i);
        }

        $keys = $this->keysOf(infos: iterator_to_array(
            iterator: $storage->listObjects(prefix: 'item/', limit: 2),
            preserve_keys: false
        ));

        $this->assertSame(['item/1', 'item/2', 'item/3', 'item/4', 'item/5'], $keys);
    }

    #[Test]
    #[TestDox('Skips objects preceding the given afterKey cursor')]
    public function testAfterKeySkipsPrecedingObjects(): void
    {
        $storage = $this->openStorage();

        foreach (['k/1', 'k/2', 'k/3'] as $key) {
            $storage->putObject(key: $key, content: $key);
        }

        $keys = $this->keysOf(infos: iterator_to_array(
            iterator: $storage->listObjects(prefix: 'k/', afterKey: 'k/1'),
            preserve_keys: false
        ));

        $this->assertSame(['k/2', 'k/3'], $keys);
    }

    #[Test]
    #[TestDox('Treats LIKE wildcards in the prefix literally')]
    public function testPrefixWithLikeWildcardsIsTreatedLiterally(): void
    {
        $storage = $this->openStorage();

        $storage->putObject(key: 'x%y.txt', content: 'percent');
        $storage->putObject(key: 'xay.txt', content: 'letter');
        $storage->putObject(key: 'x_z.txt', content: 'underscore');
        $storage->putObject(key: 'xqz.txt', content: 'letter');

        $this->assertSame(
            ['x%y.txt'],
            $this->keysOf(infos: iterator_to_array(iterator: $storage->listObjects(prefix: 'x%'), preserve_keys: false))
        );
        $this->assertSame(
            ['x_z.txt'],
            $this->keysOf(infos: iterator_to_array(iterator: $storage->listObjects(prefix: 'x_'), preserve_keys: false))
        );
    }

    /**
     * @param list<ObjectInfo> $infos
     *
     * @return list<string>
     */
    private function keysOf(array $infos): array
    {
        return array_map(callback: static fn (ObjectInfo $info): string => $info->key, array: $infos);
    }
}
