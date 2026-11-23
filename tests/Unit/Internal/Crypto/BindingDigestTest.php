<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal\Crypto;

use Etel\SqliteFileStorage\Internal\Crypto\BindingDigest;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function hash;
use function str_repeat;

/**
 * @internal
 */
#[CoversClass(BindingDigest::class)]
final class BindingDigestTest extends UnitTestCase
{
    #[Test]
    #[TestDox('The same data under the same key gives the same 64-hex digest')]
    public function testSameDataSameKey(): void
    {
        $key = str_repeat(string: 'A', times: 32);

        $digest = BindingDigest::of(key: $key, associatedData: 'bucket/a');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        $this->assertSame($digest, BindingDigest::of(key: $key, associatedData: 'bucket/a'));
    }

    #[Test]
    #[TestDox('Other data, or another key, gives another digest; none is a bare SHA-256 of the data')]
    public function testDigestIsKeyed(): void
    {
        $key = str_repeat(string: 'A', times: 32);
        $digest = BindingDigest::of(key: $key, associatedData: 'bucket/a');

        $this->assertNotSame($digest, BindingDigest::of(key: $key, associatedData: 'bucket/b'));
        /* @noinspection PhpPipeOperatorCanBeUsedInspection */
        $this->assertNotSame(
            $digest,
            BindingDigest::of(key: str_repeat(string: 'B', times: 32), associatedData: 'bucket/a')
        );
        $this->assertNotSame(hash(algo: 'sha256', data: 'bucket/a'), $digest);
    }
}
