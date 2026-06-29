<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Internal;

use Etel\SqliteFileStorage\Internal\Streams;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Stringable;

use function fopen;
use function stream_get_contents;

/**
 * @internal
 */
#[CoversClass(Streams::class)]
final class StreamsTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Returns the same resource when given a resource')]
    public function testReturnsResourceUnchanged(): void
    {
        $resource = fopen(filename: 'php://temp', mode: 'r+b');
        $this->assertIsResource($resource);

        $this->assertSame($resource, Streams::toResource(content: $resource));
    }

    #[Test]
    #[TestDox('Wraps a string into a rewound readable stream')]
    public function testWrapsString(): void
    {
        $stream = Streams::toResource(content: 'hello world');

        $this->assertSame('hello world', stream_get_contents(stream: $stream));
    }

    #[Test]
    #[TestDox('Wraps an empty string into an empty readable stream')]
    public function testWrapsEmptyString(): void
    {
        $stream = Streams::toResource(content: '');

        $this->assertSame('', stream_get_contents(stream: $stream));
    }

    #[Test]
    #[TestDox('Wraps a Stringable into a stream of its string form')]
    public function testWrapsStringable(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'from stringable';
            }
        };

        $stream = Streams::toResource(content: $stringable);

        $this->assertSame('from stringable', stream_get_contents(stream: $stream));
    }

    #[Test]
    #[TestDox('Throws for content that is neither resource, string nor Stringable')]
    public function testRejectsUnsupportedContent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Streams::toResource(content: 123);
    }
}
