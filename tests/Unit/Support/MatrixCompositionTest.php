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
        $this->assertSame('2024-02-01', $matrix['days'][0]['date']);
        $this->assertSame(['Alice', 'Bob'], array_column($matrix['rows'], 'label'));
        $alice = $matrix['rows'][0];
        $bob = $matrix['rows'][1];
        // Cells are keyed by Y-m-d date string (the blade reads
        // them by $day['date']; a legacy day-number key is only a
        // blade-side fallback).
        $this->assertSame('P', $alice['cells']['2024-02-03']);
        $this->assertSame(1, $alice['present']);
        $this->assertSame(['2024-02-01' => 'P', '2024-02-03' => 'P'], $bob['cells']);
        $this->assertSame(2, $bob['present']);
        $this->assertSame('month', $matrix['period']);
        $this->assertSame(29, $matrix['days_in_period']);
    }

    public function test_attendance_mode_glyph_cells_and_roster_fills(): void
    {
        $manifest = [
            'name' => 'Attendance',
            'ui' => ['directory' => ['matrix' => [
                'row_key' => 'user_id', 'date_field' => 'clock_in_time', 'title' => 'Attendance',
                'roster' => true, 'cell_mode' => 'attendance',
            ]]],
        ];
        $records = [
            new MatrixFakeRecord(['user_id' => 1, 'clock_in_time' => '2024-02-01 09:00:00', 'late' => 'yes']),
            new MatrixFakeRecord(['user_id' => 1, 'clock_in_time' => '2024-02-02 09:00:00', 'half_day' => 'yes']),
            new MatrixFakeRecord(['user_id' => 2, 'clock_in_time' => '2024-02-01 09:00:00']),
        ];
        $context = [
            'holidays' => ['2024-02-05' => true, '2024-02-06' => true],
            'leaves' => [1 => ['2024-02-05' => true, '2024-02-07' => true]],
            'subtitles' => [1 => 'Senior', 2 => 'Junior'],
        ];
        $matrix = DirectoryUi::matrixFromManifest($manifest, $records, 2, 2024, [1 => 'Alice', 2 => 'Bob'], $context);

        $alice = $matrix['rows'][0];
        $bob = $matrix['rows'][1];
        $this->assertSame('Senior', $alice['subtitle']);
        $this->assertSame('circle-alert', $alice['cells']['2024-02-01']['icon']); // late beats present
        $this->assertSame('star-half', $alice['cells']['2024-02-02']['icon']);
        $this->assertSame('check', $bob['cells']['2024-02-01']['icon']);
        // Roster fills: an approved leave beats a holiday on the
        // same day; a holiday alone renders the star; a leave
        // alone renders the plane.
        $this->assertSame('plane', $alice['cells']['2024-02-05']['icon']);
        $this->assertSame('star', $bob['cells']['2024-02-05']['icon']);
        $this->assertSame('star', $alice['cells']['2024-02-06']['icon']);
        $this->assertSame('plane', $alice['cells']['2024-02-07']['icon']);
        // Fills never inflate the presence count.
        $this->assertSame(2, $alice['present']);
        $this->assertSame(1, $bob['present']);
    }

    public function test_roster_week_mode_chips_window_and_legend(): void
    {
        $manifest = [
            'name' => 'EmployeeShiftSchedule',
            'ui' => ['directory' => ['matrix' => [
                'row_key' => 'user_id', 'date_field' => 'date', 'title' => 'Shift Roster',
                'roster' => true, 'cell_mode' => 'roster', 'period' => 'week',
                'shift_field' => 'employee_shift_id',
            ]]],
        ];
        $shifts = [
            1 => ['code' => 'DS', 'name' => 'Day Shift', 'color' => '#ff0000', 'text' => '#ffffff'],
            3 => ['code' => 'GS', 'name' => 'General Shift', 'color' => '#99C7F1', 'text' => '#1e293b'],
        ];
        $records = [
            new MatrixFakeRecord(['user_id' => 2, 'date' => '2024-02-05', 'employee_shift_id' => 3]),
            new MatrixFakeRecord(['user_id' => 1, 'date' => '2024-02-05', 'employee_shift_id' => 1]),
            new MatrixFakeRecord(['user_id' => 1, 'date' => '2024-02-12', 'employee_shift_id' => 1]), // next week: excluded
        ];
        $context = [
            'window' => ['2024-02-05', '2024-02-11'],
            'shifts' => $shifts,
            'holidays' => ['2024-02-08' => true],
            'row_order' => [2, 1], // population order beats label sort
        ];
        $matrix = DirectoryUi::matrixFromManifest($manifest, $records, 2, 2024, [1 => 'Alice', 2 => 'Bob'], $context);

        $this->assertSame('week', $matrix['period']);
        $this->assertSame(7, $matrix['days_in_period']);
        $this->assertSame('2024-02-05', $matrix['days'][0]['date']);
        $this->assertSame('MONDAY', $matrix['days'][0]['dow_long']);
        $this->assertSame('5 Feb - 11 Feb', $matrix['anchor_label']);
        $this->assertSame('2024-01-29', $matrix['prev_anchor']);
        $this->assertSame('2024-02-12', $matrix['next_anchor']);
        $this->assertSame(['Bob', 'Alice'], array_column($matrix['rows'], 'label'));
        $bob = $matrix['rows'][0];
        $alice = $matrix['rows'][1];
        $this->assertSame('GS', $bob['cells']['2024-02-05']['chip']['code']);
        $this->assertSame('DS', $alice['cells']['2024-02-05']['chip']['code']);
        $this->assertSame(1, $alice['present']); // the out-of-window record is excluded
        $this->assertSame('star', $alice['cells']['2024-02-08']['icon']); // roster holiday fill
        $this->assertSame(['DS', 'GS'], array_column($matrix['legend_chips'], 'code'));
    }

    public function test_contrast_text_picks_readable_foreground(): void
    {
        $this->assertSame('#ffffff', DirectoryUi::contrastText('#ff0000'));
        $this->assertSame('#ffffff', DirectoryUi::contrastText('#4d4c4c'));
        $this->assertSame('#1e293b', DirectoryUi::contrastText('#99C7F1'));
        $this->assertSame('#1e293b', DirectoryUi::contrastText('#E8EEF3'));
        $this->assertSame('#1e293b', DirectoryUi::contrastText('not-a-color'));
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
