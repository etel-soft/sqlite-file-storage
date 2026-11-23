<?php

/* @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Integration;

use Etel\SqliteFileStorage\Encryption;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Internal\Crypto\BindingDigest;
use Etel\SqliteFileStorage\PutOptions;
use Etel\SqliteFileStorage\Storage;
use Etel\SqliteFileStorage\StorageOptions;
use Etel\SqliteFileStorage\Tests\IntegrationTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use SQLite3;

use function hash;
use function random_bytes;

/**
 * Encrypted content bound to associated data: readable only with the same data, so a replica moved under another
 * name does not decrypt there.
 *
 * @internal
 */
final class AssociatedDataTest extends IntegrationTestCase
{
    #[Test]
    #[TestDox('Bound content reads back with the same associated data')]
    public function testBoundContentReadsWithTheSameData(): void
    {
        $storage = $this->encryptedStorage();
        $content = random_bytes(length: 700_000);

        $info = $storage->putObject(key: 'a', content: $content, options: $this->bound(associatedData: 'bucket/a'));

        $this->assertTrue($info->boundToAssociatedData);
        $this->assertSame($content, $storage->getObject(key: 'a', associatedData: 'bucket/a')->getContents());
    }

    #[Test]
    #[TestDox('Bound content is refused with other associated data, or none')]
    #[TestWith(['bucket/b'])]
    #[TestWith([''])]
    public function testBoundContentIsRefusedWithOtherData(string $associatedData): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'secret', options: $this->bound(associatedData: 'bucket/a'));

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('associated data differs from the one bound');

        $storage->getObject(key: 'a', associatedData: $associatedData);
    }

    #[Test]
    #[TestDox('Content swapped between two objects does not decrypt under the other one')]
    public function testSwappedContentDoesNotDecrypt(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));
        $storage->putObject(key: 'b', content: 'content of b', options: $this->bound(associatedData: 'bucket/b'));

        $this->tamper(sql: <<<'SQL'
            UPDATE objects SET content_id = CASE key
                WHEN 'a' THEN (SELECT content_id FROM objects WHERE key = 'b')
                ELSE (SELECT content_id FROM objects WHERE key = 'a') END
            SQL);

        $this->expectException(ContentIntegrityException::class);

        $storage->getObject(key: 'a', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('Bound content with its binding struck out of the record still does not decrypt without the data')]
    public function testStrippedBindingDoesNotDecrypt(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));

        $this->tamper(sql: "UPDATE contents SET enc_context = json_remove(enc_context, '$.ad')");

        $object = $storage->getObject(key: 'a');
        $this->assertFalse($object->info->boundToAssociatedData);

        $this->expectException(ContentIntegrityException::class);

        $object->getContents();
    }

    #[Test]
    #[TestDox('Unbound content put in the place of bound content is refused with the associated data')]
    public function testUnboundContentInPlaceOfBoundIsRefused(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));
        $storage->putObject(key: 'planted', content: 'planted content', options: $this->bound(associatedData: ''));

        $this->tamper(sql: <<<'SQL'
            UPDATE objects SET content_id = (SELECT content_id FROM objects WHERE key = 'planted') WHERE key = 'a'
            SQL);

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('content is not bound to associated data');

        $storage->getObject(key: 'a', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('Unbound content given a forged binding does not decrypt with the associated data')]
    public function testForgedBindingDoesNotDecrypt(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'planted content', options: $this->bound(associatedData: ''));

        // the digest of the right data, as if the forger had it: the cipher is what refuses
        $digest = BindingDigest::of(key: $this->encryptionKey(), associatedData: 'bucket/a');
        /* @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
        $this->tamper(sql: "UPDATE contents SET enc_context = json_set(enc_context, '$.ad', '{$digest}')");

        $object = $storage->getObject(key: 'a', associatedData: 'bucket/a');
        $this->assertTrue($object->info->boundToAssociatedData);

        $this->expectException(ContentIntegrityException::class);

        $object->getContents();
    }

    #[Test]
    #[TestDox('Plain content put in the place of bound content is refused with the associated data')]
    public function testPlainContentInPlaceOfBoundIsRefused(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));
        $storage->putObject(key: 'planted', content: 'planted content', options: new PutOptions(
            encryption: Encryption::None
        ));

        $this->tamper(sql: <<<'SQL'
            UPDATE objects SET content_id = (SELECT content_id FROM objects WHERE key = 'planted') WHERE key = 'a'
            SQL);

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('content is not encrypted');

        $storage->getObject(key: 'a', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('A binding of the wrong type is a malformed record')]
    public function testMalformedBindingIsRefused(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));

        $this->tamper(sql: "UPDATE contents SET enc_context = json_set(enc_context, '$.ad', 42)");

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('malformed encryption context');

        $storage->getObject(key: 'a', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('Unbound content — written before binding or without it — reads without associated data only')]
    public function testUnboundContentReadsWithoutDataOnly(): void
    {
        $storage = $this->encryptedStorage();

        $info = $storage->putObject(key: 'a', content: 'old content', options: $this->bound(associatedData: ''));

        $this->assertFalse($info->boundToAssociatedData);
        $this->assertSame('old content', $storage->getObject(key: 'a')->getContents());

        $this->expectException(ContentIntegrityException::class);
        $this->expectExceptionMessageIsOrContains('content is not bound to associated data');

        $storage->getObject(key: 'a', associatedData: 'bucket/a');
    }

    #[Test]
    #[TestDox('Associated data without encryption is refused: nothing would hold it')]
    public function testAssociatedDataWithoutEncryptionIsRefused(): void
    {
        $storage = $this->encryptedStorage();

        try {
            $storage->putObject(key: 'a', content: 'plain', options: new PutOptions(
                encryption: Encryption::None,
                associatedData: 'bucket/a'
            ));
            $this->fail('Associated data was accepted without encryption.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('encrypted content only', $exception->getMessage());
        }

        $this->assertFalse($storage->hasObject(key: 'a'));
    }

    #[Test]
    #[TestDox('Identical content is shared only under the same associated data')]
    public function testDeduplicationKeepsBindingsApart(): void
    {
        $storage = $this->encryptedStorage();

        $storage->putObject(key: 'a', content: 'same bytes', options: $this->bound(associatedData: 'bucket/ref'));
        $storage->putObject(key: 'b', content: 'same bytes', options: $this->bound(associatedData: 'bucket/ref'));
        $this->assertSame(1, $storage->getStats()->contentCount);

        $storage->putObject(key: 'c', content: 'same bytes', options: $this->bound(associatedData: 'bucket/other'));
        $storage->putObject(key: 'd', content: 'same bytes', options: $this->bound(associatedData: ''));
        $this->assertSame(3, $storage->getStats()->contentCount);

        $this->assertSame(
            'same bytes',
            $storage->getObject(key: 'c', associatedData: 'bucket/other')->getContents()
        );
    }

    #[Test]
    #[TestDox('The record keeps a digest keyed by the content key, not a bare hash of the data')]
    public function testRecordKeepsAKeyedDigest(): void
    {
        $storage = $this->encryptedStorage();
        $storage->putObject(key: 'a', content: 'content of a', options: $this->bound(associatedData: 'bucket/a'));
        $storage->close();

        $db = new SQLite3(filename: $this->databasePath());
        $digest = $db->querySingle(query: "SELECT json_extract(enc_context, '$.ad') FROM contents");
        $db->close();

        $this->assertSame(BindingDigest::of(key: $this->encryptionKey(), associatedData: 'bucket/a'), $digest);
        $this->assertNotSame(hash(algo: 'sha256', data: 'bucket/a'), $digest);
    }

    private function bound(string $associatedData): PutOptions
    {
        return new PutOptions(encryption: Encryption::XChaCha20Poly1305, associatedData: $associatedData);
    }

    private function encryptedStorage(): Storage
    {
        return $this->openStorage(options: new StorageOptions(keyProvider: $this->keyProvider()));
    }

    private function tamper(string $sql): void
    {
        $db = new SQLite3(filename: $this->databasePath());
        $db->busyTimeout(milliseconds: 5000);
        $db->exec(query: $sql);
        $db->close();
    }
}
