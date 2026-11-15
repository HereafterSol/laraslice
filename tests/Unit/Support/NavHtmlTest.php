<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\NavHtml;
use LaraSlice\Support\NavStructure;
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

    public function test_shell_ships_active_group_full_and_others_lazy(): void
    {
        $nav = [
            ['title' => 'Workspace', 'items' => [
                ['title' => 'Dashboard', 'url' => '/', 'icon' => 'layout-dashboard'],
            ]],
            ['title' => 'HR', 'items' => [
                ['title' => 'Employees', 'url' => '/hr/employees', 'icon' => 'users'],
            ]],
            ['title' => 'Finance', 'items' => [
                ['title' => 'Invoices', 'url' => '/finance/invoices', 'icon' => 'wallet'],
            ]],
        ];

        $shell = NavHtml::renderShell($nav, 'HR');

        // Workspace and the active group ship their items.
        $this->assertStringContainsString('href="/hr/employees"', $shell);
        $this->assertStringContainsString('href="/"', $shell);
        // The inactive group is a lazy shell: header present,
        // items absent, loader hooks in place.
        $this->assertStringContainsString('data-nav-lazy="Finance"', $shell);
        $this->assertStringContainsString('data-nav-lazy-toggle', $shell);
        $this->assertStringContainsString('data-nav-lazy-list', $shell);
        // The item itself is not shipped (the group header's
        // first-item link is chrome, not the nav item).
        $this->assertStringNotContainsString('title="Invoices"', $shell);
    }

    public function test_group_items_fragment_renders_only_items(): void
    {
        $html = NavHtml::renderGroupItems(['title' => 'Finance', 'items' => [
            ['title' => 'Invoices', 'url' => '/finance/invoices', 'icon' => 'wallet'],
        ]]);

        $this->assertStringContainsString('href="/finance/invoices"', $html);
        $this->assertStringNotContainsString('data-slot="sidebar-group"', $html);
    }

    public function test_lazy_script_wires_group_and_palette_loaders(): void
    {
        $script = NavHtml::lazyScript('/laraslice/nav-group', '/laraslice/nav-index');
        $this->assertStringContainsString('data-nav-lazy-toggle', $script);
        $this->assertStringContainsString('__lsLoadPalette', $script);
        $this->assertStringContainsString('"/laraslice/nav-index"', $script);
    }

    public function test_section_headers_render_as_labels_not_links(): void
    {
        $html = NavHtml::renderGroupItems(['title' => 'CRM', 'items' => [
            ['header' => 'Sales Pipeline', 'title' => 'Sales Pipeline', 'url' => '', 'icon' => '', 'children' => []],
            ['title' => 'Leads', 'url' => '/crm/leads', 'icon' => 'box', 'children' => []],
        ]]);

        $this->assertStringContainsString('Sales Pipeline', $html);
        $this->assertStringContainsString('href="/crm/leads"', $html);
        // The header is a label row: exactly one link (the item).
        $this->assertSame(1, substr_count($html, 'data-nav-link'));
    }

    public function test_with_sections_orders_unsectioned_first_then_sections(): void
    {
        $method = new \ReflectionMethod(NavStructure::class, 'withSections');
        $method->setAccessible(true);
        $out = $method->invoke(null, [
            ['title' => 'Territory', 'url' => '/t', 'order' => 90, 'section' => 'Setup', 'children' => []],
            ['title' => 'Leads', 'url' => '/l', 'order' => 20, 'section' => null, 'children' => []],
            ['title' => 'Contracts', 'url' => '/c', 'order' => 60, 'section' => 'Sales Pipeline', 'children' => []],
            ['title' => 'Opportunities', 'url' => '/o', 'order' => 30, 'section' => null, 'children' => []],
            ['title' => 'Appointments', 'url' => '/a', 'order' => 70, 'section' => 'Sales Pipeline', 'children' => []],
        ]);

        $titles = array_map(static fn ($i) => $i['header'] ?? $i['title'], $out);
        $this->assertSame(
            ['Leads', 'Opportunities', 'Sales Pipeline', 'Contracts', 'Appointments', 'Setup', 'Territory'],
            $titles
        );
    }
}
