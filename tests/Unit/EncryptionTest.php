<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use const JSON_THROW_ON_ERROR;

use function json_encode;

/**
 * @internal
 */
#[CoversClass(Encryption::class)]
final class EncryptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Each case maps to its expected string value')]
    public function testCaseValues(): void
    {
        $this->assertSame('none', Encryption::None->value);
        $this->assertSame('xchacha20poly1305', Encryption::XChaCha20Poly1305->value);
    }

    #[Test]
    #[TestDox('Encoding to JSON returns the backing value for every case')]
    public function testJsonSerializeReturnsValue(): void
    {
        foreach (Encryption::cases() as $case) {
            $this->assertSame('"' . $case->value . '"', json_encode(value: $case, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    #[TestDox('from round-trips a backing value to its case')]
    public function testFromRoundTrip(): void
    {
        $this->assertSame(Encryption::None, Encryption::from(value: 'none'));
        $this->assertSame(
            Encryption::XChaCha20Poly1305,
            Encryption::from(value: Encryption::XChaCha20Poly1305->value)
        );
    }
}
