<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * The declared record timer (G8 item 5) resolves from
 * ui.show.timer with the log vocabulary defaulting to the
 * source domain's field names.
 */
final class TimerCompositionTest extends TestCase
{
    public function test_it_resolves_a_timer_declaration_with_defaults(): void
    {
        $decl = DirectoryUi::timerFromManifest([
            'ui' => ['show' => ['timer' => ['relation' => 'timeLogs']]],
        ]);

        $this->assertSame('timeLogs', $decl['relation']);
        $this->assertSame('start_time', $decl['start_field']);
        $this->assertSame('end_time', $decl['end_field']);
        $this->assertSame('user_id', $decl['user_field']);
        $this->assertSame('Timer', $decl['title']);
    }

    public function test_it_honours_declared_field_names(): void
    {
        $decl = DirectoryUi::timerFromManifest([
            'ui' => ['show' => ['timer' => [
                'relation' => 'sessions',
                'start_field' => 'began_at',
                'end_field' => 'ended_at',
                'user_field' => 'member_id',
                'title' => 'Work Session',
            ]]],
        ]);

        $this->assertSame('sessions', $decl['relation']);
        $this->assertSame('began_at', $decl['start_field']);
        $this->assertSame('ended_at', $decl['end_field']);
        $this->assertSame('member_id', $decl['user_field']);
        $this->assertSame('Work Session', $decl['title']);
    }

    public function test_it_returns_null_without_a_relation(): void
    {
        $this->assertNull(DirectoryUi::timerFromManifest(['ui' => ['show' => []]]));
        $this->assertNull(DirectoryUi::timerFromManifest(['ui' => ['show' => ['timer' => ['title' => 'X']]]]));
        $this->assertNull(DirectoryUi::timerFromManifest([]));
    }
}
