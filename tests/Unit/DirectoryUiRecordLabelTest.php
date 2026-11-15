<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * D-053: DirectoryUi::recordLabel's last-resort fallback —
 * a record whose identity lives in a stem column
 * (market_segment, lost_reason, description…) must title
 * its form with that value, not fall through to null
 * (the generated blade then prints the slice class name).
 */
final class DirectoryUiRecordLabelTest extends TestCase
{
    private function record(array $attributes): Model
    {
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->setRawAttributes($attributes);

        return $model;
    }

    public function test_stem_column_resolves_as_label(): void
    {
        $record = $this->record(['id' => 1, 'market_segment' => 'Benchmark Segment']);

        self::assertSame('Benchmark Segment', DirectoryUi::recordLabel($record));
    }

    public function test_conventional_columns_still_win(): void
    {
        $record = $this->record(['id' => 1, 'market_segment' => 'Stem', 'name' => 'Real Name']);

        self::assertSame('Real Name', DirectoryUi::recordLabel($record));
    }

    public function test_foreign_keys_and_shaped_values_are_skipped(): void
    {
        $record = $this->record([
            'id' => 7,
            'parent_id' => 3,
            'status' => 'open',
            'contact_email' => 'a@example.com',
            'starts_on' => '2026-01-01',
            'website' => 'https://example.com',
            'description' => 'The actual identity',
        ]);

        self::assertSame('The actual identity', DirectoryUi::recordLabel($record));
    }

    public function test_record_without_any_text_identity_returns_null(): void
    {
        $record = $this->record(['id' => 2, 'status' => 'open', 'parent_id' => 5]);

        self::assertNull(DirectoryUi::recordLabel($record));
    }
}
