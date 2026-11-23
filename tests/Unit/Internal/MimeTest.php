<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Internal\Mime;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function base64_decode;

/**
 * @internal
 */
#[CoversClass(Mime::class)]
final class MimeTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Detects the type from the leading bytes')]
    public function testDetectsFromBytes(): void
    {
        $png = base64_decode(
            string: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            strict: true
        );

        $this->assertSame('image/png', Mime::detect(leadingBytes: (string) $png));
        $this->assertSame('text/plain', Mime::detect(leadingBytes: 'plain words'));
    }

    #[Test]
    #[TestDox('Nothing to look at, no type')]
    public function testEmptyHasNoType(): void
    {
        $this->assertNull(Mime::detect(leadingBytes: ''));
    }
}
