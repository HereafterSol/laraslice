<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Porting\DesignLint;
use PHPUnit\Framework\TestCase;

class DesignLintTest extends TestCase
{
    public function test_black_on_white_is_21_to_1(): void
    {
        $ratio = DesignLint::contrastRatio('oklch(0 0 0)', 'oklch(1 0 0)');
        $this->assertEqualsWithDelta(21.0, $ratio, 0.6);
    }

    public function test_the_kits_declared_pairs_clear_aa(): void
    {
        $md = file_get_contents(__DIR__.'/../../../DESIGN.md');
        $tokens = DesignLint::parseTokens($md);
        $this->assertArrayHasKey('muted-foreground', $tokens);
        $this->assertSame([], DesignLint::contrastFindings($tokens));
    }

    public function test_a_washed_out_token_fails(): void
    {
        $tokens = ['foreground' => 'oklch(0.75 0 0)', 'background' => 'oklch(1 0 0)'];
        $findings = DesignLint::contrastFindings($tokens);
        $this->assertNotSame([], $findings);
        $this->assertSame('error', $findings[0]['severity']);
    }

    public function test_blade_patterns(): void
    {
        $dirty = '<div class="bg-[#ff0000] p-[13px]" style="color: red">x</div>';
        $kinds = array_column(DesignLint::bladeFindings($dirty, 'app/Slices/X'), 'kind');
        $this->assertContains('lint.hardcoded-colour', $kinds);
        $this->assertContains('lint.inline-style', $kinds);
        $this->assertContains('lint.arbitrary-spacing', $kinds);

        $clean = '<div class="bg-card p-3 text-sm">x</div>';
        $this->assertSame([], DesignLint::bladeFindings($clean, 'app/Slices/X'));
    }

    public function test_presentation_mismatch_is_an_error(): void
    {
        $f = DesignLint::presentationFindings('leaves', ['create' => 'page'], ['create' => 'drawer']);
        $this->assertCount(1, $f);
        $this->assertSame('lint.presentation-mismatch', $f[0]['kind']);
        $this->assertSame('error', $f[0]['severity']);
    }

    public function test_presentation_match_is_silent_and_undeclared_warns(): void
    {
        $this->assertSame([], DesignLint::presentationFindings('projects', ['create' => 'page', 'edit' => 'page'], ['create' => 'page', 'edit' => 'page']));
        $f = DesignLint::presentationFindings('tasks', ['create' => 'page'], []);
        $this->assertCount(1, $f);
        $this->assertSame('lint.presentation-undeclared', $f[0]['kind']);
        $this->assertSame('warning', $f[0]['severity']);
    }

    public function test_presentation_unknown_capture_stays_silent(): void
    {
        $this->assertSame([], DesignLint::presentationFindings('attendances', ['create' => 'unknown'], ['create' => 'drawer']));
    }
}
