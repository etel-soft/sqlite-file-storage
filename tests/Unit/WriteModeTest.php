<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Tests\UnitTestCase;
use Etel\SqliteFileStorage\WriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function array_map;

/**
 * @internal
 */
#[CoversClass(WriteMode::class)]
final class WriteModeTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Defines exactly the Overwrite and CreateOnly cases')]
    public function testCases(): void
    {
        $names = array_map(callback: static fn (WriteMode $mode): string => $mode->name, array: WriteMode::cases());

        $this->assertSame(['Overwrite', 'CreateOnly'], $names);
    }
}
