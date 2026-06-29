<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(StorageReadOnlyException::class)]
final class StorageReadOnlyExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method forOperation() includes the rejected operation name')]
    public function testForOperation(): void
    {
        $exception = StorageReadOnlyException::forOperation(operation: 'putObject');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(StorageReadOnlyException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('putObject', $exception->getMessage());
    }
}
