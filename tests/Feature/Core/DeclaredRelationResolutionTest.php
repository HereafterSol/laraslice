<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Support\DataTableRows;
use LaraSlice\Tests\TestCase;

/**
 * F10 (Day-2 drill, 8 Nov 2026): a generated slice whose field
 * belongsTo an employee_details-backed record rendered the
 * employee as the raw id everywhere — the form picker's table
 * conventions never tried employee_details (employee →
 * employees/employee), and employee_details has no name column
 * (the name lives on users via user_id), so even a resolved
 * table labelled its options with ids.
 *
 * Pinned behaviour:
 *  - option resolution follows the DECLARED relation (the root
 *    model's relations entry, or the field's own references)
 *    before any table-name convention;
 *  - a person-shaped target (no label column, user_id → users)
 *    labels its options and its list/show cells with the
 *    person's NAME, through the framework's users-join person
 *    mechanism (DataTableRows::personNameMap);
 *  - personNameMap returns null for tables that are not
 *    person-shaped, so other fallbacks keep working.
 */
final class DeclaredRelationResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test app ships Laravel's users table (name lives there).
        Schema::create('employee_details', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('employee_id')->nullable();
        });
        Schema::create('training_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('course_name');
        });
        Schema::create('departments', function ($table) {
            $table->id();
            $table->string('department_name');
        });

        DB::table('users')->insert([
            ['id' => 23, 'name' => 'Fabiola Rempel', 'email' => 'fabiola@example.com', 'password' => 'x', 'status' => 'active'],
            ['id' => 24, 'name' => 'Admin User', 'email' => 'admin@example.com', 'password' => 'x', 'status' => 'active'],
        ]);
        DB::table('employee_details')->insert([
            ['id' => 12, 'user_id' => 23, 'employee_id' => 'EMP-012'],
            ['id' => 13, 'user_id' => 24, 'employee_id' => 'EMP-013'],
        ]);
    }

    private function controllerWithManifest(array $manifest): BaseSliceWebController
    {
        return new class($manifest) extends BaseSliceWebController
        {
            public function __construct(private array $manifestStub) {}

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
                return $this->manifestStub;
            }

            public function options(mixed $form): array
            {
                return $this->resolveRelationshipOptions($form);
            }
        };
    }

    public function test_picker_follows_the_declared_root_relation_and_labels_people_by_name(): void
    {
        $controller = $this->controllerWithManifest([
            'fields' => ['employee_id' => ['name' => 'employee_id', 'type' => 'foreign_id']],
            'models' => [[
                'handle' => 'training_record',
                'table' => 'training_records',
                'root' => true,
                'relations' => [[
                    'name' => 'employee',
                    'type' => 'belongsTo',
                    'model' => 'employee_detail',
                    'foreign_key' => 'employee_id',
                    'table' => 'employee_details',
                ]],
            ]],
        ]);

        $options = $controller->options((object) ['employee_id' => null]);

        $this->assertSame('Fabiola Rempel', $options['employeesOptions'][12] ?? null);
        $this->assertSame('Admin User', $options['employeesOptions'][13] ?? null);
        $this->assertSame('Fabiola Rempel', $options['employeeOptions'][12] ?? null);
    }

    public function test_picker_follows_a_field_level_references_declaration(): void
    {
        $controller = $this->controllerWithManifest([
            'fields' => ['employee_id' => [
                'name' => 'employee_id',
                'type' => 'foreign_id',
                'relation' => ['name' => 'employee', 'model' => 'employee_detail'],
                'references' => 'employee_details',
            ]],
            'models' => [],
        ]);

        $options = $controller->options((object) ['employee_id' => 13]);

        $this->assertSame('Fabiola Rempel', $options['employeesOptions'][12] ?? null);
        $this->assertSame('Admin User', $options['employeesOptions'][13] ?? null);
    }

    public function test_list_rows_render_the_person_name_for_a_declared_relation_target(): void
    {
        DB::table('training_records')->insert([
            ['id' => 1, 'employee_id' => 12, 'course_name' => 'First Aid'],
        ]);
        $record = F10TrainingRecord::query()->firstOrFail();

        $rows = DataTableRows::from([$record], [
            ['key' => 'employee_id'],
            ['key' => 'course_name'],
        ]);

        $this->assertSame('Fabiola Rempel', $rows[0]['employee_id']);
        $this->assertSame('First Aid', $rows[0]['course_name']);
    }

    public function test_composite_user_column_renders_the_person_behind_the_declared_relation(): void
    {
        // The trap the drill hit: employee_details #12 belongs to
        // user #23 (Fabiola), but users #12 is somebody else. A
        // composite_user cell that treats the FK value as a user
        // id renders the WRONG person; the column's declared
        // relation must win.
        DB::table('users')->insert(['id' => 12, 'name' => 'Lina Walter', 'email' => 'lina@example.test', 'password' => 'x']);
        DB::table('training_records')->insert(['id' => 1, 'employee_id' => 12, 'course_name' => 'First Aid']);

        $record = F10TrainingRecord::query()->firstOrFail();
        $rows = DataTableRows::from([$record], [[
            'key' => 'employee_id', 'label' => 'Employee',
            'formatter' => 'composite_user', 'relation' => 'employee',
        ]]);

        $this->assertStringContainsString('Fabiola Rempel', $rows[0]['employee_id']);
        $this->assertStringNotContainsString('Lina Walter', $rows[0]['employee_id']);
    }

    public function test_business_object_rows_resolve_people_through_the_declared_label_table(): void
    {
        // Listing rows are BusinessObjects, not Eloquent models:
        // no relation method exists to walk, so the live list
        // kept rendering the wrong person even with a working
        // model path. The column's declared label_table is the
        // pointer both cell paths must honour.
        DB::table('users')->insert(['id' => 12, 'name' => 'Lina Walter', 'email' => 'lina@example.test', 'password' => 'x']);
        $bo = (object) ['id' => 1, 'employee_id' => 12, 'course_name' => 'First Aid'];

        $composite = DataTableRows::from([$bo], [[
            'key' => 'employee_id', 'label' => 'Employee',
            'formatter' => 'composite_user', 'label_table' => 'employee_details',
        ]]);
        $this->assertStringContainsString('Fabiola Rempel', $composite[0]['employee_id']);
        $this->assertStringNotContainsString('Lina Walter', $composite[0]['employee_id']);

        $labelled = DataTableRows::from([$bo], [[
            'key' => 'employee_id', 'label_table' => 'employee_details',
        ], ['key' => 'course_name']]);
        $this->assertSame('Fabiola Rempel', $labelled[0]['employee_id']);
    }

    public function test_person_name_map_only_claims_person_shaped_tables(): void
    {
        $this->assertSame(
            [12 => 'Fabiola Rempel', 13 => 'Admin User'],
            DataTableRows::personNameMap('employee_details')
        );
        // departments: no user_id — not person-shaped.
        $this->assertNull(DataTableRows::personNameMap('departments'));
        // users itself has no user_id either.
        $this->assertNull(DataTableRows::personNameMap('users'));
        $this->assertNull(DataTableRows::personNameMap('missing_table'));
    }
}

final class F10EmployeeDetail extends Model
{
    protected $table = 'employee_details';

    public $timestamps = false;

    protected $guarded = [];
}

final class F10TrainingRecord extends Model
{
    protected $table = 'training_records';

    public $timestamps = false;

    protected $guarded = [];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(F10EmployeeDetail::class, 'employee_id');
    }
}
