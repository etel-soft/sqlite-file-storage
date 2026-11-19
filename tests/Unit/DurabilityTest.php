<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Durability;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function array_map;

/**
 * @internal
 */
#[CoversClass(Durability::class)]
final class DurabilityTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Defines exactly the Buffered and Synced cases')]
    public function testCases(): void
    {
        $cases = array_map(
            callback: static fn (Durability $case): array => [$case->name, $case->value],
            array: Durability::cases()
        );

        $this->assertSame([['Buffered', 'buffered'], ['Synced', 'synced']], $cases);
    }
}
