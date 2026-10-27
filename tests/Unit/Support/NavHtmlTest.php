<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\NavHtml;
use PHPUnit\Framework\TestCase;

/**
 * Sidebar rendering (backlog B3): plain-string markup with
 * the same slots/classes the component stack produced —
 * data-nav-link anchors for client-side active marking,
 * parents as data-nav-parent collapsibles, titles escaped,
 * and the active script emitted separately from the
 * cacheable fragment.
 */
final class NavHtmlTest extends TestCase
{
    public function test_render_leaf_parent_and_escaping(): void
    {
        $html = NavHtml::render([
            ['title' => 'HR', 'items' => [
                ['title' => 'Employees', 'url' => '/hr/employees', 'icon' => 'users'],
                ['title' => 'Admin <script>', 'url' => '/admin', 'icon' => 'shield', 'children' => [
                    ['title' => 'All Users', 'url' => '/admin/users'],
                ]],
            ]],
            ['title' => 'Empty', 'items' => []],
        ]);

        $this->assertStringContainsString('data-nav-link', $html);
        $this->assertStringContainsString('href="/hr/employees"', $html);
        $this->assertStringContainsString('data-nav-parent', $html);
        $this->assertStringContainsString('href="/admin/users"', $html);
        $this->assertStringContainsString('Admin &lt;script&gt;', $html);
        $this->assertStringNotContainsString('Admin <script>', $html);
        // No server-baked active state in the fragment.
        $this->assertStringNotContainsString('data-active', $html);
        // Group header links to its first item.
        $this->assertStringContainsString('title="Go to HR"', $html);
    }

    public function test_active_script_marks_longest_match_and_opens_parent(): void
    {
        $script = NavHtml::activeScript();
        $this->assertStringContainsString('data-nav-link', $script);
        $this->assertStringContainsString("setAttribute('data-active', 'true')", $script);
        $this->assertStringContainsString('data-nav-parent', $script);
    }
}
