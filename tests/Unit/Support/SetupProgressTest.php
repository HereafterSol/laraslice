<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SetupProgress;
use PHPUnit\Framework\TestCase;

/**
 * The setup checklist rules (G1): the source's probes,
 * evaluated purely — placeholder SMTP is incomplete,
 * base steps are done, percent floors like the source's
 * bar width.
 */
class SetupProgressTest extends TestCase
{
    public function test_fresh_install_scores_two_of_six(): void
    {
        $r = SetupProgress::evaluate(['users' => 3, 'smtp_from' => 'from@email.com', 'cron' => null, 'logo' => null, 'favicon' => null]);

        $this->assertSame(6, $r['total']);
        $this->assertSame(2, $r['completed']);
        $this->assertSame(33, $r['percent']);
        $done = array_column(array_filter($r['steps'], fn ($s) => $s['done']), 'key');
        $this->assertSame(['installation', 'account_setup'], array_values($done));
    }

    public function test_completed_setup_scores_full(): void
    {
        $r = SetupProgress::evaluate(['users' => 1, 'smtp_from' => 'hello@acme.test', 'cron' => '2026-10-25 00:00:00', 'logo' => 'logo.png', 'favicon' => 'favicon.ico']);

        $this->assertSame(6, $r['completed']);
        $this->assertSame(100, $r['percent']);
    }

    public function test_no_users_means_account_setup_open(): void
    {
        $r = SetupProgress::evaluate(['users' => 0, 'smtp_from' => 'a@b.c', 'cron' => 'x', 'logo' => 'l', 'favicon' => 'f']);

        $this->assertSame(5, $r['completed']);
    }
}
