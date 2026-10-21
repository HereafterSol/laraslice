<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * G2: ui.directory.matrix turns flat dated records into a
 * rows x days-of-month grid with per-row presence counts.
 */
class MatrixCompositionTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'name' => 'Attendance',
            'ui' => ['directory' => ['matrix' => ['row_key' => 'user_id', 'date_field' => 'clock_in_time', 'title' => 'Attendance']]],
        ];
    }

    public function test_matrix_buckets_records_by_row_and_day(): void
    {
        $records = [
            new MatrixFakeRecord(['user_id' => 2, 'clock_in_time' => '2024-02-01 09:00:00']),
            new MatrixFakeRecord(['user_id' => 2, 'clock_in_time' => '2024-02-03 09:00:00']),
            new MatrixFakeRecord(['user_id' => 1, 'clock_in_time' => '2024-02-03 10:00:00']),
            new MatrixFakeRecord(['user_id' => 1, 'clock_in_time' => '2024-03-01 10:00:00']), // other month: excluded
        ];
        $matrix = DirectoryUi::matrixFromManifest($this->manifest(), $records, 2, 2024, [1 => 'Alice', 2 => 'Bob']);

        $this->assertNotNull($matrix);
        $this->assertCount(29, $matrix['days']); // Feb 2024 is a leap month
        $this->assertSame(['Alice', 'Bob'], array_column($matrix['rows'], 'label'));
        $alice = $matrix['rows'][0];
        $bob = $matrix['rows'][1];
        $this->assertSame('P', $alice['cells'][3]);
        $this->assertSame(1, $alice['present']);
        $this->assertSame([1 => 'P', 3 => 'P'], $bob['cells']);
        $this->assertSame(2, $bob['present']);
    }

    public function test_matrix_requires_declaration(): void
    {
        $this->assertNull(DirectoryUi::matrixFromManifest(['name' => 'X'], [], 2, 2024));
    }
}

class MatrixFakeRecord
{
    public function __construct(private array $attrs) {}

    public function getAttributes(): array
    {
        return $this->attrs;
    }
}
