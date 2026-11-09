<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Support\DashboardUi;
use LaraSlice\Tests\TestCase;

/**
 * Dashboard audience + scope enforcement (flagship audit
 * Phase 2, item 7): a widget's declared `roles:` audience is
 * honoured, and when the slice's Heart declares permissions
 * the signed-in user's view scope gates and narrows its
 * widgets exactly as the slice controllers gate and narrow
 * lists — 'none' drops the slice's widgets (no dead links,
 * no leaked totals), owned narrows to the viewer's records
 * via field AND pivot clauses.
 */
class DashboardScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['permission_role', 'role_user', 'permissions', 'permission_types', 'roles', 'users', 'dash_things', 'dash_thing_users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
        });
        Schema::create('permission_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('user_id');
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_type_id')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
        });
        Schema::create('dash_things', function (Blueprint $t) {
            $t->id();
            $t->string('heading');
            $t->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('dash_thing_users', function (Blueprint $t) {
            $t->unsignedBigInteger('thing_id');
            $t->unsignedBigInteger('user_id');
        });

        DB::table('permission_types')->insert([
            ['id' => 1, 'name' => 'added'],
            ['id' => 2, 'name' => 'owned'],
            ['id' => 3, 'name' => 'both'],
            ['id' => 4, 'name' => 'all'],
            ['id' => 5, 'name' => 'none'],
        ]);
        DB::table('roles')->insert([
            ['id' => 10, 'name' => 'admin'],
            ['id' => 11, 'name' => 'employee'],
            ['id' => 12, 'name' => 'client'],
        ]);
        DB::table('permissions')->insert([
            ['id' => 20, 'name' => 'View Things', 'slug' => 'thing.view'],
        ]);
        DB::table('users')->insert([
            ['id' => 101, 'name' => 'Admin', 'email' => 'a@example.com'],
            ['id' => 102, 'name' => 'Employee', 'email' => 'e@example.com'],
            ['id' => 103, 'name' => 'Client', 'email' => 'c@example.com'],
        ]);
        DB::table('role_user')->insert([
            ['role_id' => 10, 'user_id' => 101],
            ['role_id' => 11, 'user_id' => 102],
            ['role_id' => 12, 'user_id' => 103],
        ]);
        // Admin sees everything; employee sees her own; the
        // client holds no grant at all.
        DB::table('permission_role')->insert([
            ['permission_id' => 20, 'role_id' => 10, 'permission_type_id' => 4],
            ['permission_id' => 20, 'role_id' => 11, 'permission_type_id' => 2],
        ]);
        DB::table('dash_things')->insert([
            ['id' => 1, 'heading' => 'Mine One', 'user_id' => 102],
            ['id' => 2, 'heading' => 'Mine Two', 'user_id' => 102],
            ['id' => 3, 'heading' => 'Mine Three', 'user_id' => 102],
            ['id' => 4, 'heading' => 'Admin One', 'user_id' => 101],
            ['id' => 5, 'heading' => 'Admin Two', 'user_id' => 101],
            ['id' => 6, 'heading' => 'Stray', 'user_id' => 999],
        ]);
        DashboardUi::flush();
    }

    private function user(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    private function manifest(array $widgets, array $extra = []): array
    {
        return array_merge([
            'name' => 'Things',
            'permissions' => ['thing.view'],
            'access' => ['own_scope' => [['field' => 'user_id']]],
            'fields' => ['user_id' => [], 'added_by' => []],
            'ui' => ['dashboard' => ['widgets' => $widgets]],
        ], $extra);
    }

    public function test_scope_none_drops_the_slice_widgets_entirely(): void
    {
        $this->actingAs($this->user(103));

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'total', 'type' => 'counter', 'label' => 'Total'],
        ]), DashboardScopeThing::class, ['index' => '/things', 'show' => '/things/{id}']);

        $this->assertSame([], $widgets);
    }

    public function test_scope_none_still_yields_the_own_record_profile_widget(): void
    {
        // The profile widget is own-record by construction: a
        // user whose role holds no view grant on the slice
        // (an employee on the people slice) still gets her own
        // card — and nothing else from that slice.
        DB::table('dash_things')->insert([
            ['id' => 7, 'heading' => 'Client Own', 'user_id' => 103],
        ]);
        $this->actingAs($this->user(103));

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'total', 'type' => 'counter', 'label' => 'Total'],
            ['handle' => 'me', 'type' => 'profile', 'title' => 'My Profile'],
        ]), DashboardScopeThing::class, ['index' => '/things', 'show' => '/things/{id}']);

        $this->assertCount(1, $widgets);
        $this->assertSame('profile', $widgets[0]['type']);
        $this->assertSame('/things/7', $widgets[0]['url']);
        // The card's link is the record itself, never the
        // directory the viewer cannot open.
        $this->assertSame('/things/7', $widgets[0]['link']);
    }

    public function test_owned_scope_narrows_the_counter_and_title_names_the_slice(): void
    {
        $this->actingAs($this->user(102));

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'total', 'type' => 'counter', 'label' => 'Total'],
        ]), DashboardScopeThing::class, ['index' => '/things', 'show' => '/things/{id}']);

        $this->assertCount(1, $widgets);
        $this->assertSame('3', $widgets[0]['value']);
        $this->assertSame('Things', $widgets[0]['title']);
    }

    public function test_all_scope_counts_everything(): void
    {
        $this->actingAs($this->user(101));

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'total', 'type' => 'counter', 'label' => 'Total'],
        ]), DashboardScopeThing::class, ['index' => '/things', 'show' => '/things/{id}']);

        $this->assertCount(1, $widgets);
        $this->assertSame('6', $widgets[0]['value']);
    }

    public function test_pivot_own_scope_clause_narrows_the_counter(): void
    {
        DB::table('dash_thing_users')->insert([
            ['thing_id' => 4, 'user_id' => 102],
            ['thing_id' => 5, 'user_id' => 102],
        ]);
        $this->actingAs($this->user(102));

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'total', 'type' => 'counter', 'label' => 'Total'],
        ], ['access' => ['own_scope' => [['pivot' => 'dash_thing_users', 'foreign_key' => 'thing_id', 'user_key' => 'user_id']]]]),
            DashboardScopeThing::class, ['index' => '/things', 'show' => '/things/{id}']);

        $this->assertCount(1, $widgets);
        $this->assertSame('2', $widgets[0]['value']);
    }

    public function test_roles_audience_is_honoured(): void
    {
        $manifest = [
            'name' => 'Things',
            'ui' => ['dashboard' => ['widgets' => [
                ['handle' => 'admin_only', 'type' => 'counter', 'title' => 'Admin Only', 'roles' => ['admin']],
                ['handle' => 'everyone', 'type' => 'counter', 'title' => 'Everyone'],
            ]]],
        ];

        $this->actingAs($this->user(102));
        $handles = array_column(DashboardUi::widgetsFromManifest($manifest, DashboardScopeThing::class), 'handle');
        $this->assertSame(['everyone'], $handles);

        $this->actingAs($this->user(101));
        $handles = array_column(DashboardUi::widgetsFromManifest($manifest, DashboardScopeThing::class), 'handle');
        $this->assertSame(['admin_only', 'everyone'], $handles);
    }

    public function test_own_scoped_widget_uses_pivot_clauses(): void
    {
        DB::table('dash_thing_users')->insert([
            ['thing_id' => 1, 'user_id' => 102],
            ['thing_id' => 4, 'user_id' => 102],
        ]);
        $this->actingAs($this->user(101));

        $widgets = DashboardUi::widgetsFromManifest([
            'name' => 'Things',
            'ui' => ['dashboard' => ['widgets' => [
                ['handle' => 'mine', 'type' => 'counter', 'title' => 'Mine', 'scope' => 'own',
                    'own' => ['pivot' => 'dash_thing_users', 'foreign_key' => 'thing_id', 'user_key' => 'user_id']],
            ]]],
        ], DashboardScopeThing::class);

        // scope: own always speaks about the signed-in user
        // (here the admin, linked to nothing) — never widened.
        $this->assertCount(1, $widgets);
        $this->assertSame('0', $widgets[0]['value']);
    }

    public function test_own_record_url_fails_closed_without_a_declaring_slice(): void
    {
        $this->assertNull(DashboardUi::ownRecordUrl());

        $this->actingAs($this->user(102));
        // The test app discovers no slice declaring a profile
        // widget: the helper must return no link rather than
        // guess at a people slice.
        $this->assertNull(DashboardUi::ownRecordUrl());
    }
}

class DashboardScopeThing extends Model
{
    protected $table = 'dash_things';

    protected $guarded = [];

    public $timestamps = false;
}
