<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\ArrayKeyProvider;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Internal\ContentHash;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function fclose;
use function fopen;
use function fread;
use function ftell;
use function fwrite;
use function hash;
use function hash_final;
use function hash_hmac;
use function hash_update;
use function rewind;
use function str_repeat;

/**
 * @internal
 */
#[CoversClass(ContentHash::class)]
final class ContentHashTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Without a key the hash is the SHA-256 of the content')]
    public function testBareHashIsSha256(): void
    {
        $this->assertSame(hash(algo: 'sha256', data: 'content'), new ContentHash()->of(content: 'content'));
        $this->assertSame(hash(algo: 'sha256', data: ''), new ContentHash()->of(content: ''));
    }

    #[Test]
    #[TestDox('With a key the hash is an HMAC-SHA256 under a key derived from it: the stored format, never to change')]
    public function testKeyedHashIsHmacUnderDerivedKey(): void
    {
        $key = str_repeat(string: 'H', times: 32);
        $derived = hash_hmac(algo: 'sha256', data: 'etel-sfs content hash v1', key: $key, binary: true);

        $hash = new ContentHash(key: $key)->of(content: 'content');

        $this->assertSame(hash_hmac(algo: 'sha256', data: 'content', key: $derived), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    #[TestDox('A keyed hash is neither the bare one nor an HMAC under the key itself, and differs by key')]
    public function testKeyedHashDiffers(): void
    {
        $key = str_repeat(string: 'H', times: 32);
        $hash = new ContentHash(key: $key)->of(content: 'content');

        $this->assertSame($hash, new ContentHash(key: $key)->of(content: 'content'));
        $this->assertNotSame(hash(algo: 'sha256', data: 'content'), $hash);
        $this->assertNotSame(hash_hmac(algo: 'sha256', data: 'content', key: $key), $hash);
        $this->assertNotSame(new ContentHash(key: str_repeat(string: 'I', times: 32))->of(content: 'content'), $hash);
        $this->assertNotSame(new ContentHash(key: $key)->of(content: 'other'), $hash);
    }

    #[Test]
    #[TestDox('A stream is hashed from where it is to its end, and left open')]
    public function testStreamIsHashedFromItsPosition(): void
    {
        $stream = fopen(filename: 'php://memory', mode: 'r+b');
        $this->assertIsResource($stream);
        fwrite(stream: $stream, data: 'skipped content');
        rewind(stream: $stream);
        fread(stream: $stream, length: 8);

        $hash = new ContentHash()->of(content: $stream);

        $this->assertSame(hash(algo: 'sha256', data: 'content'), $hash);
        // a closed stream would fail here: it is open, and read to its end
        $this->assertSame(15, ftell(stream: $stream));

        fclose(stream: $stream);
    }

    #[Test]
    #[TestDox('The context it starts gives the same hash as hashing the content at once')]
    public function testStartedContextGivesTheSameHash(): void
    {
        $hasher = new ContentHash(key: str_repeat(string: 'H', times: 32));
        $context = $hasher->start();

        hash_update(context: $context, data: 'con');
        hash_update(context: $context, data: 'tent');

        $this->assertSame($hasher->of(content: 'content'), hash_final(context: $context));
    }

    #[Test]
    #[TestDox('Resolved without a key id, it is the bare hash and asks no provider')]
    public function testResolvedWithoutKeyIdIsBare(): void
    {
        $hash = ContentHash::resolve(keyProvider: null, keyId: null)->of(content: 'content');

        $this->assertSame(hash(algo: 'sha256', data: 'content'), $hash);
    }

    #[Test]
    #[TestDox('Resolved with a key id, it is the hash under that key of the provider')]
    public function testResolvedWithKeyId(): void
    {
        $key = str_repeat(string: 'H', times: 32);
        $provider = new ArrayKeyProvider(
            keys: ['content' => str_repeat(string: 'C', times: 32), 'hash' => $key],
            currentKeyId: 'content'
        );

        $hash = ContentHash::resolve(keyProvider: $provider, keyId: 'hash')->of(content: 'content');

        $this->assertSame(new ContentHash(key: $key)->of(content: 'content'), $hash);
    }

    #[Test]
    #[TestDox('A key id without a key provider is refused, not hashed bare')]
    public function testKeyIdWithoutProviderIsRefused(): void
    {
        $this->expectException(EncryptionKeyNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('hash key');

        ContentHash::resolve(keyProvider: null, keyId: 'hash');
    }

    #[Test]
    #[TestDox('A key id the provider does not know is refused')]
    public function testUnknownKeyIdIsRefused(): void
    {
        $provider = new ArrayKeyProvider(
            keys: ['content' => str_repeat(string: 'C', times: 32)],
            currentKeyId: 'content'
        );

        $this->expectException(EncryptionKeyNotFoundException::class);

        ContentHash::resolve(keyProvider: $provider, keyId: 'hash');
    }

    #[Test]
    #[TestDox('A key that is not 32 bytes is refused')]
    public function testKeyOfWrongLengthIsRefused(): void
    {
        $provider = new ArrayKeyProvider(keys: ['hash' => 'short'], currentKeyId: 'hash');

        $this->expectException(InvalidEncryptionKeyException::class);

        ContentHash::resolve(keyProvider: $provider, keyId: 'hash');
    }
}
