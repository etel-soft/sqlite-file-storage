<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Internal\Uuid7;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function array_unique;

/**
 * @internal
 */
#[CoversClass(Uuid7::class)]
final class Uuid7Test extends UnitTestCase
{
    #[Test]
    #[TestDox('Generates identifiers in the RFC 9562 version 7 format')]
    public function testGeneratesRfc9562Version7(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                Uuid7::generate(),
            );
        }
    }

    #[Test]
    #[TestDox('Generates unique values across many calls')]
    public function testGeneratesUniqueValues(): void
    {
        $uuids = [];

        for ($i = 0; $i < 1000; ++$i) {
            $uuids[] = Uuid7::generate();
        }

        $this->assertCount(1000, array_unique(array: $uuids));
    }
}
