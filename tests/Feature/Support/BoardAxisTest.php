<?php

namespace LaraSlice\Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Tests\TestCase;

/**
 * Board-axis vocabulary + picker-role resolution (Phase 4,
 * gaps G4/G8): DirectoryUi::boardAxis derives a directory's
 * board axis exactly the way the generated index blades do and
 * resolves its full vocabulary — boards render every column,
 * empty ones included. pickerRole/roleMemberIds are the shared
 * contract behind role-filtered people pickers.
 */
class BoardAxisTest extends TestCase
{
    public function test_board_axis_resolves_the_fk_vocabulary_from_the_target_table(): void
    {
        Schema::dropIfExists('taskboard_columns');
        Schema::create('taskboard_columns', function ($table) {
            $table->id();
            $table->string('column_name');
            $table->integer('priority');
        });
        foreach (['Incomplete', 'To Do', 'Doing', 'Completed'] as $i => $name) {
            DB::table('taskboard_columns')->insert(['column_name' => $name, 'priority' => $i + 1]);
        }

        $axis = DirectoryUi::boardAxis([
            'ui' => ['directory' => ['columns' => [
                ['key' => 'heading', 'label' => 'Heading'],
                ['key' => 'board_column_id', 'label' => 'Status'],
            ]]],
        ]);

        $this->assertNotNull($axis);
        $this->assertSame('board_column_id', $axis['key']);
        $this->assertSame(
            [
                ['value' => '1', 'label' => 'Incomplete'],
                ['value' => '2', 'label' => 'To Do'],
                ['value' => '3', 'label' => 'Doing'],
                ['value' => '4', 'label' => 'Completed'],
            ],
            $axis['options']
        );
    }

    public function test_board_axis_prefers_a_literal_status_column_and_its_declared_options(): void
    {
        $axis = DirectoryUi::boardAxis([
            'ui' => ['directory' => ['columns' => [
                ['key' => 'board_column_id', 'label' => 'Status'],
                ['key' => 'status', 'label' => 'State', 'options' => ['open' => 'Open', 'closed' => 'Closed']],
            ]]],
        ]);

        $this->assertNotNull($axis);
        $this->assertSame('status', $axis['key']);
        $this->assertSame(
            [['value' => 'open', 'label' => 'Open'], ['value' => 'closed', 'label' => 'Closed']],
            $axis['options']
        );
    }

    public function test_board_axis_is_null_without_a_resolvable_axis(): void
    {
        $this->assertNull(DirectoryUi::boardAxis([
            'ui' => ['directory' => ['columns' => [['key' => 'heading', 'label' => 'Heading']]]],
        ]));
        $this->assertNull(DirectoryUi::boardAxis([]));
    }

    public function test_picker_role_convention_declaration_and_absence(): void
    {
        $this->assertSame('client', DirectoryUi::pickerRole([], 'client'));
        $this->assertSame('agent', DirectoryUi::pickerRole([], 'agent'));
        $this->assertNull(DirectoryUi::pickerRole([], 'user'));
        $this->assertSame('employee', DirectoryUi::pickerRole(['options_role' => 'employee'], 'user'));
    }

    public function test_role_member_ids_resolve_membership_and_fail_open(): void
    {
        Schema::dropIfExists('roles');
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::dropIfExists('role_user');
        Schema::create('role_user', function ($table) {
            $table->integer('role_id');
            $table->integer('user_id');
        });
        DB::table('roles')->insert([
            ['id' => 6, 'name' => 'employee'],
            ['id' => 7, 'name' => 'client'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => 7, 'user_id' => 3],
            ['role_id' => 7, 'user_id' => 4],
            ['role_id' => 6, 'user_id' => 2],
        ]);

        $this->assertSame([3, 4], DirectoryUi::roleMemberIds('client'));
        $this->assertSame([2], DirectoryUi::roleMemberIds('employee'));
        // A role the app does not carry resolves to null —
        // callers leave the picker unfiltered, never emptied.
        $this->assertNull(DirectoryUi::roleMemberIds('agent'));
    }
}
