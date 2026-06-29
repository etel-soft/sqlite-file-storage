<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit;

use Etel\SqliteFileStorage\Compression;
use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use Etel\SqliteFileStorage\WriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(PutOptions::class)]
final class PutOptionsTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Applies the documented defaults')]
    public function testDefaults(): void
    {
        $options = new PutOptions();

        $this->assertNull($options->compression);
        $this->assertNull($options->encryption);
        $this->assertSame(WriteMode::Overwrite, $options->writeMode);
        $this->assertNull($options->id);
        $this->assertNull($options->name);
        $this->assertNull($options->mimeType);
        $this->assertSame([], $options->meta);
        $this->assertTrue($options->deduplicate);
    }

    #[Test]
    #[TestDox('Exposes every explicitly provided value')]
    public function testCustomValues(): void
    {
        $options = new PutOptions(
            compression: Compression::Brotli,
            encryption: Encryption::XChaCha20Poly1305,
            writeMode: WriteMode::CreateOnly,
            id: '01890a5d-ac96-774b-bcce-b302099a8057',
            name: 'report.pdf',
            mimeType: 'application/pdf',
            meta: ['owner' => 'mykola', 'version' => 3],
            deduplicate: false
        );

        $this->assertSame(Compression::Brotli, $options->compression);
        $this->assertSame(Encryption::XChaCha20Poly1305, $options->encryption);
        $this->assertSame(WriteMode::CreateOnly, $options->writeMode);
        $this->assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $options->id);
        $this->assertSame('report.pdf', $options->name);
        $this->assertSame('application/pdf', $options->mimeType);
        $this->assertSame(['owner' => 'mykola', 'version' => 3], $options->meta);
        $this->assertFalse($options->deduplicate);
    }
}
