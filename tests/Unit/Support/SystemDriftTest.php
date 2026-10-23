<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SystemDrift;
use PHPUnit\Framework\TestCase;

class SystemDriftTest extends TestCase
{
    public function test_club_promise_nobody_grants_is_error_and_empty_role_is_flagged(): void
    {
        $mind = ['roles' => ['clubs' => [
            ['name' => 'Super Admin', 'permissions' => ['*']],
            ['name' => 'Employee', 'permissions' => ['leaves.apply', 'tasks.view-own']],
        ]]];
        $grants = ['Super Administrator' => ['leaves.view'], 'Standard User' => []];
        $registry = ['leaves.view', 'leaves.create', 'tasks.view'];

        $findings = SystemDrift::audit($mind, $grants, $registry);
        $kinds = array_column($findings, 'kind');

        // leaves.apply requires leaves.create: NO role grants it -> club-ungranted (error)
        $this->assertContains('club-ungranted', $kinds);
        // tasks.view-own: scope note (warning)
        $this->assertContains('scope-declared', $kinds);
        // Standard User grants nothing -> role-empty (error)
        $this->assertContains('role-empty', $kinds);
        $errors = array_filter($findings, fn ($f) => $f['severity'] === 'error');
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function test_mapped_club_with_full_grants_is_quiet(): void
    {
        $mind = ['roles' => ['clubs' => [
            ['name' => 'Employee', 'permissions' => ['leaves.apply']],
        ]]];
        $grants = ['Employee' => ['leaves.view', 'leaves.create']];
        $registry = ['leaves.view', 'leaves.create'];

        $findings = SystemDrift::audit($mind, $grants, $registry);
        $this->assertSame([], $findings);
    }

    public function test_body_access_ungranted_is_error(): void
    {
        $bodies = ['leaves' => ['access' => [
            'Employee' => ['actions' => ['view', 'create'], 'scope' => 'own'],
        ]]];
        $grants = ['Employee' => ['leaves.view']];
        $registry = ['leaves.view', 'leaves.create'];

        $findings = SystemDrift::audit([], $grants, $registry, $bodies);
        $kinds = array_column($findings, 'kind');
        $this->assertContains('body-access-ungranted', $kinds);
        $this->assertContains('scope-declared', $kinds);
    }

    public function test_singular_grant_satisfies_plural_requirement(): void
    {
        // The runtime accepts leaf.create for a leaves.create
        // requirement (AuthorizesSliceActions candidates).
        $mind = ['roles' => ['clubs' => [
            ['name' => 'Employee', 'permissions' => ['leaves.apply']],
        ]]];
        $grants = ['Employee' => ['leaf.view', 'leaf.create']];
        $registry = ['leaf.view', 'leaf.create'];

        $findings = SystemDrift::audit($mind, $grants, $registry);
        $this->assertSame([], $findings);
    }

    public function test_reconciliation_plans_view_own_constructs_never_full_view(): void
    {
        $mind = ['roles' => ['clubs' => [
            ['name' => 'Employee', 'maps_to' => 'Standard User', 'permissions' => ['task.view-own', 'leaves.apply']],
        ]]];
        $grants = ['Standard User' => ['leaf.view-own']];
        $registry = ['leaf.view', 'leaf.create', 'leaf.view-own', 'task.view'];

        $plan = SystemDrift::planReconciliation($mind, $grants, $registry);
        $bySlug = [];
        foreach ($plan as $a) {
            $bySlug[$a['slug']] = $a['action'];
        }
        // view-own: the construct is created + granted; the
        // full task.view must NOT be granted (that would widen
        // own-scope to everyone''s records).
        $this->assertSame('create-and-grant', $bySlug['task.view-own'] ?? null);
        $this->assertArrayNotHasKey('task.view', $bySlug);
        // leaves.apply -> leaf.create exists -> plain grant.
        $this->assertSame('grant', $bySlug['leaf.create'] ?? null);
    }
}
