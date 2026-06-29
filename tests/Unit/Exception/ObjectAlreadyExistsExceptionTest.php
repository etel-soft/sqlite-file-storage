<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests\Unit\Exception;

use Etel\SqliteFileStorage\Exception\ObjectAlreadyExistsException;
use Etel\SqliteFileStorage\Exception\StorageException;
use Etel\SqliteFileStorage\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @internal
 */
#[CoversClass(ObjectAlreadyExistsException::class)]
final class ObjectAlreadyExistsExceptionTest extends UnitTestCase
{
    #[Test]
    #[TestDox('Method byKey() includes the object key')]
    public function testByKey(): void
    {
        $exception = ObjectAlreadyExistsException::byKey(key: 'invoices/2026/0001.pdf');

        /* @noinspection PhpConditionAlreadyCheckedInspection */
        /* @noinspection UnnecessaryAssertionInspection */
        $this->assertInstanceOf(ObjectAlreadyExistsException::class, $exception);
        /* @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(StorageException::class, $exception);
        $this->assertStringContainsString('invoices/2026/0001.pdf', $exception->getMessage());
    }
}
