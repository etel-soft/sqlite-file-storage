<?php

declare(strict_types=1);

namespace Etel\SqliteFileStorage\Tests;

use Etel\PHPUnitDouble\Helper\MockHelperTrait;
use Etel\PHPUnitDouble\Helper\StubHelperTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for all unit tests in the library.
 */
abstract class UnitTestCase extends TestCase
{
    use MockHelperTrait;
    use StubHelperTrait;
}
