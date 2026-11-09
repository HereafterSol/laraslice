<?php

namespace LaraSlice\Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Tests\TestCase;

class RelationshipOptionsTest extends TestCase
{
    public function test_options_are_capped_but_keep_the_selected_value(): void
    {
        Schema::create('brands', function ($table) {
            $table->id();
            $table->string('name');
        });
        foreach (range(1, 30) as $i) {
            DB::table('brands')->insert(['name' => sprintf('Brand %02d', $i)]);
        }
        config(['laraslice.forms.relationship_options_limit' => 10]);

        $controller = new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('unused');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
        $options = $controller->options((object) ['brand_id' => 30])['brandsOptions'];

        $this->assertCount(11, $options);
        $this->assertSame('Brand 30', $options[30]);
        $this->assertSame('Brand 01', $options[1]);
    }

    /**
     * Regression pin (flagship audit, Phase 1 item 4): the Tasks
     * form's Board Column picker (board_column_id) must resolve
     * the source's taskboard_columns table — the pluralization
     * guess (board_columns) finds nothing and the select renders
     * empty in every generated form.
     */
    public function test_board_column_options_resolve_the_taskboard_table(): void
    {
        Schema::create('taskboard_columns', function ($table) {
            $table->id();
            $table->string('column_name');
            $table->integer('priority');
        });
        foreach (['Incomplete', 'To Do', 'Doing', 'Completed'] as $i => $name) {
            DB::table('taskboard_columns')->insert(['column_name' => $name, 'priority' => $i + 1]);
        }

        $controller = new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('unused');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
        $options = $controller->options((object) ['board_column_id' => 2])['boardColumnsOptions'];

        // The resolver orders by the label column; the contract
        // pinned here is the id -> label map itself.
        ksort($options);
        $this->assertSame([1 => 'Incomplete', 2 => 'To Do', 3 => 'Doing', 4 => 'Completed'], $options);
    }

    /**
     * Regression pin (flagship audit, Phase 3 sweep): the client
     * picker (client_id) must resolve the users table — clients
     * are users in the source schema (the client() relation
     * resolves to the User model, and DirectoryUi already aliases
     * client -> users). The pluralization guess (clients) finds
     * no table and the select renders with zero options, so
     * UI-created invoices/contracts stored client_id NULL.
     */
    public function test_client_options_resolve_the_users_table(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
        DB::table('users')->insert([
            ['name' => 'Client One'],
            ['name' => 'Client Two'],
        ]);

        $controller = new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('unused');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
        $options = $controller->options((object) ['client_id' => null])['clientsOptions'];

        ksort($options);
        $this->assertSame([1 => 'Client One', 2 => 'Client Two'], $options);

        // Same alias class: agent pickers resolve users as well.
        $agentOptions = $controller->options((object) ['agent_id' => null])['agentsOptions'];
        ksort($agentOptions);
        $this->assertSame([1 => 'Client One', 2 => 'Client Two'], $agentOptions);
    }

    /**
     * Role-filter contract (Phase 4, gap G8): a client picker
     * lists only the client role's members — the source system's
     * pickers never offer employees/admins as clients. A role
     * that does not exist in the app (agent, here) leaves its
     * picker unfiltered rather than emptied on a guess.
     */
    public function test_client_picker_lists_only_client_role_members(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
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
        DB::table('users')->insert([
            ['name' => 'Admin Anna'],
            ['name' => 'Employee Eric'],
            ['name' => 'Client Carla'],
            ['name' => 'Client Carl'],
        ]);
        DB::table('roles')->insert([
            ['id' => 5, 'name' => 'admin'],
            ['id' => 6, 'name' => 'employee'],
            ['id' => 7, 'name' => 'client'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => 5, 'user_id' => 1],
            ['role_id' => 6, 'user_id' => 2],
            ['role_id' => 7, 'user_id' => 3],
            ['role_id' => 7, 'user_id' => 4],
        ]);

        $controller = $this->optionsController();
        $options = $controller->options((object) ['client_id' => null])['clientsOptions'];
        ksort($options);
        $this->assertSame([3 => 'Client Carla', 4 => 'Client Carl'], $options);

        // No 'agent' role exists in this app: the agent picker
        // falls back to the unfiltered users list.
        $agentOptions = $controller->options((object) ['agent_id' => null])['agentsOptions'];
        ksort($agentOptions);
        $this->assertCount(4, $agentOptions);
    }

    /**
     * A field may declare its role binding explicitly
     * (options_role) — a plain user picker bound to the employee
     * role then lists employees only, with no convention at play.
     */
    public function test_declared_options_role_binds_a_user_picker(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
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
        DB::table('users')->insert([
            ['name' => 'Employee Eric'],
            ['name' => 'Client Carla'],
        ]);
        DB::table('roles')->insert([
            ['id' => 6, 'name' => 'employee'],
            ['id' => 7, 'name' => 'client'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => 6, 'user_id' => 1],
            ['role_id' => 7, 'user_id' => 2],
        ]);

        $controller = new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('unused');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            protected function ownManifest(): array
            {
                return ['fields' => ['user_id' => ['options_role' => 'employee']]];
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
        $options = $controller->options((object) ['user_id' => null])['usersOptions'];
        ksort($options);
        $this->assertSame([1 => 'Employee Eric'], $options);
    }

    private function optionsController(): BaseSliceWebController
    {
        return new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('unused');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
    }
}
