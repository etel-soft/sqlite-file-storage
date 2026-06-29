<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(ObjectNotFoundException::class)]
final class ObjectNotFoundExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method byKey() includes the object key')]
    public function testByKey(): void
    {
        $exception = ObjectNotFoundException::byKey(key: 'missing.txt');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(ObjectNotFoundException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('missing.txt', $exception->getMessage());
    }
}
