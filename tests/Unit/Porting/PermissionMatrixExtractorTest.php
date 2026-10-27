<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\PermissionMatrixExtractor;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Permission-matrix extraction (backlog C3): the source's
 * scoped role matrix normalizes into proposed LaraSlice
 * slugs — view-own for scoped views, parent-collapsed
 * alternatives for compound (hub-child) nouns, 'none'
 * counted but never granted, all-'all' roles marked
 * super.
 */
final class PermissionMatrixExtractorTest extends TestCase
{
    public function test_proposals_map_verbs_scopes_and_compounds(): void
    {
        $this->assertSame(
            ['base' => 'project', 'action' => 'view', 'custom' => false],
            PermissionMatrixExtractor::propose('view_projects')
        );
        $this->assertSame('create', PermissionMatrixExtractor::propose('add_tickets')['action']);
        $this->assertSame('update', PermissionMatrixExtractor::propose('edit_task_comments')['action']);
        $this->assertSame('leaf', PermissionMatrixExtractor::propose('view_leaves')['base']);
        $this->assertTrue(PermissionMatrixExtractor::propose('approve_invoices')['custom']);
        $this->assertNull(PermissionMatrixExtractor::propose('frobnicate_widgets'));
        $this->assertNull(PermissionMatrixExtractor::propose('standalone'));
    }

    public function test_extract_normalizes_a_scoped_matrix(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE modules (id INTEGER PRIMARY KEY, module_name TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT, module_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_types (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE permission_role (permission_id INTEGER, role_id INTEGER, permission_type_id INTEGER)');
        $pdo->exec("INSERT INTO permission_types VALUES (1, 'added'), (2, 'owned'), (3, 'both'), (4, 'all'), (5, 'none')");
        $pdo->exec("INSERT INTO modules VALUES (1, 'Projects')");
        $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'client')");
        $pdo->exec("INSERT INTO permissions VALUES (1, 'view_projects', 1), (2, 'view_project_files', 1), (3, 'add_tickets', 1)");
        // admin: all everywhere (super); client: owned projects, all project files, none tickets
        $pdo->exec('INSERT INTO permission_role VALUES (1, 1, 4), (2, 1, 4), (3, 1, 4)');
        $pdo->exec('INSERT INTO permission_role VALUES (1, 2, 2), (2, 2, 4), (3, 2, 5)');

        $matrix = PermissionMatrixExtractor::extract($pdo);

        $this->assertSame([], $matrix['notes']);
        $this->assertSame(3, $matrix['totals']['permissions']);
        $this->assertSame(5, $matrix['totals']['effective_grants']);

        [$admin, $client] = [$matrix['roles'][0], $matrix['roles'][1]];
        $this->assertTrue($admin['super']);
        $this->assertSame(['*'], $admin['club']['permissions']);

        $this->assertFalse($client['super']);
        $this->assertCount(2, $client['grants']);
        $bySource = collect($client['grants'])->keyBy('source');
        $this->assertSame('project.view-own', $bySource['view_projects']['slug']);
        $this->assertSame('project_file.view', $bySource['view_project_files']['slug']);
        $this->assertSame('project.view', $bySource['view_project_files']['alt_slug']);
        $this->assertSame(['project.view-own', 'project_file.view'], $client['club']['permissions']);
    }

    public function test_extract_without_the_tables_is_an_empty_matrix_with_a_note(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $matrix = PermissionMatrixExtractor::extract($pdo);
        $this->assertSame([], $matrix['roles']);
        $this->assertNotSame([], $matrix['notes']);
    }
}
