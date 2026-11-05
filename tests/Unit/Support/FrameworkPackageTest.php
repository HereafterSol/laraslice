<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\FrameworkPackage;
use LaraSlice\Tests\TestCase;

class FrameworkPackageTest extends TestCase
{
    public function test_schema_telemetry_label_reflects_sqlite_driver(): void
    {
        $this->assertSame('SQLite schemas', FrameworkPackage::schemaTelemetryLabel());
    }

    public function test_version_is_non_empty_string(): void
    {
        $version = FrameworkPackage::version();

        $this->assertNotSame('', $version);
        $this->assertMatchesRegularExpression('/^v?\d+\.\d+\.\d+|^dev$/', $version);
    }

    public function test_host_slice_count_returns_sensible_integer(): void
    {
        $count = FrameworkPackage::hostSliceCount();

        $this->assertGreaterThanOrEqual(0, $count);
    }
}
