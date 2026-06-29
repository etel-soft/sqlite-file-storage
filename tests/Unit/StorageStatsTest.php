<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\StorageStats;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(StorageStats::class)]
final class StorageStatsTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Exposes every constructor argument as a public property')]
    public function testExposesAllProperties(): void
    {
        $stats = new StorageStats(objectCount: 5, contentCount: 3, totalSize: 2048, totalStoredSize: 900);

        $this->assertSame(5, $stats->objectCount);
        $this->assertSame(3, $stats->contentCount);
        $this->assertSame(2048, $stats->totalSize);
        $this->assertSame(900, $stats->totalStoredSize);
    }
}
