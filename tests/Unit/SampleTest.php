<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SampleTest extends TestCase
{
    public function test_environment_boots_properly(): void
    {
        $dbName = env('DB_DATABASE', 'stationery_shop');
        $this->assertSame('stationery_shop', $dbName);
        $this->assertNotEmpty($dbName);
    }
}
