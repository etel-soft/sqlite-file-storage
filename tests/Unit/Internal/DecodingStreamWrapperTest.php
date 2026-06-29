<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Internal\Codec\PassthroughCodec;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\DecodingStreamWrapper;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

use function fopen;
use function fstat;
use function fwrite;
use function in_array;
use function rewind;
use function str_repeat;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;

/**
 * @internal
 */
#[CoversClass(DecodingStreamWrapper::class)]
final class DecodingStreamWrapperTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Reads plain content back across multiple fill chunks')]
    public function testReadsPlainContentAcrossChunks(): void
    {
        $content = str_repeat(string: 'decoding-stream-wrapper payload. ', times: 20000);

        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);
        fwrite(stream: $source, data: $content);
        rewind(stream: $source);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: strlen(string: $content),
            plainSize: strlen(string: $content)
        );

        $stat = fstat(stream: $decoded);
        $this->assertNotFalse($stat);
        $this->assertSame(strlen(string: $content), $stat['size']);
        $this->assertSame($content, stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Decodes empty content to an empty stream')]
    public function testDecodesEmptyContent(): void
    {
        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);

        $decoded = DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: 0,
            plainSize: 0
        );

        $this->assertSame('', stream_get_contents(stream: $decoded));
    }

    #[Test]
    #[TestDox('Registers its stream wrapper protocol on first use')]
    public function testRegistersStreamWrapperProtocol(): void
    {
        if (in_array(needle: DecodingStreamWrapper::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister(protocol: DecodingStreamWrapper::PROTOCOL);
        }

        $source = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($source);

        DecodingStreamWrapper::open(
            source: $source,
            decryptor: new PassthroughCrypto(),
            decompressor: new PassthroughCodec(),
            cipherChunkSize: 0,
            storedSize: 0,
            plainSize: 0
        );

        $this->assertContains(DecodingStreamWrapper::PROTOCOL, stream_get_wrappers());
    }

    #[Test]
    #[TestDox('Refuses to open when the stream context is missing required options')]
    public function testRejectsInvalidStreamContext(): void
    {
        if (!in_array(needle: DecodingStreamWrapper::PROTOCOL, haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(protocol: DecodingStreamWrapper::PROTOCOL, class: DecodingStreamWrapper::class);
        }

        $context = stream_context_create(options: [
            DecodingStreamWrapper::PROTOCOL => ['source' => 'not-a-resource'],
        ]);

        $this->assertFalse(@fopen(
            filename: DecodingStreamWrapper::PROTOCOL . '://object',
            mode: 'rb',
            context: $context
        ));
    }
}
