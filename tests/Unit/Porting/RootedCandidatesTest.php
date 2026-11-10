<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\PermissionMatrixExtractor;
use LaraSlice\Tests\TestCase;

/**
 * Flat source-shaped proposals (projects.view) must root at
 * the target's hierarchical declared slugs
 * (erp.adjudication.work.projects.view) — uniquely, or not
 * at all. Before this, the --permissions pass's club drafts
 * could never resolve on a hierarchical port, so a cold
 * porter pasting them into the Mind got a wall of drift
 * errors in a dialect the target does not speak.
 */
final class RootedCandidatesTest extends TestCase
{
    public function test_unique_tail_roots_the_proposal(): void
    {
        $rooted = PermissionMatrixExtractor::rootedCandidates(
            ['projects.view', 'tasks.create'],
            ['erp.adjudication.work.projects.view', 'erp.adjudication.work.tasks.view', 'erp.adjudication.work.tasks.create']
        );

        $this->assertSame('erp.adjudication.work.projects.view', $rooted['projects.view'] ?? null);
        $this->assertSame('erp.adjudication.work.tasks.create', $rooted['tasks.create'] ?? null);
    }

    public function test_ambiguous_tail_roots_nothing(): void
    {
        $rooted = PermissionMatrixExtractor::rootedCandidates(
            ['projects.view'],
            ['erp.adjudication.work.projects.view', 'ops.projects.view']
        );

        $this->assertSame([], $rooted);
    }

    public function test_view_own_roots_at_the_declared_view(): void
    {
        $rooted = PermissionMatrixExtractor::rootedCandidates(
            ['projects.view-own'],
            ['erp.adjudication.work.projects.view']
        );

        $this->assertSame('erp.adjudication.work.projects.view-own', $rooted['projects.view-own'] ?? null);
    }

    public function test_star_and_unknown_proposals_are_ignored(): void
    {
        $rooted = PermissionMatrixExtractor::rootedCandidates(
            ['*', 'nonexistent.view'],
            ['erp.adjudication.work.projects.view']
        );

        $this->assertSame([], $rooted);
    }

    public function test_update_and_edit_verbs_propose_the_framework_edit_action(): void
    {
        // Declared slugs speak 'edit' (project_file.edit);
        // source matrices speak 'update' (update_tasks) and
        // 'edit' (edit_tasks) — both must propose 'edit' or
        // they can never resolve against the registry.
        $this->assertSame('edit', PermissionMatrixExtractor::propose('update_tasks')['action'] ?? null);
        $this->assertSame('edit', PermissionMatrixExtractor::propose('edit_tasks')['action'] ?? null);
        $this->assertSame('task', PermissionMatrixExtractor::propose('update_tasks')['base'] ?? null);
    }
}
