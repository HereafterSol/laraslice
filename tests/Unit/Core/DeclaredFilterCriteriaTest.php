<?php

namespace LaraSlice\Tests\Unit\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Base\BaseFilter;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use PHPUnit\Framework\TestCase;

/**
 * G5: declared-filter criteria must actually narrow list queries.
 * Before this, quick-filter tabs and sidebar filter declarations
 * never reached the query — `?status=completed` returned every row.
 */
class DeclaredFilterCriteriaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Capsule::schema()->create('widgets', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->integer('project_id')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });
        foreach ([
            ['title' => 'A', 'status' => 'completed', 'project_id' => 1, 'due_date' => '2026-01-10'],
            ['title' => 'B', 'status' => 'incomplete', 'project_id' => 1, 'due_date' => '2026-02-10'],
            ['title' => 'C', 'status' => 'completed', 'project_id' => 2, 'due_date' => '2026-03-10'],
        ] as $row) {
            WidgetModel::create($row);
        }
    }

    private function titlesWithCriteria(array $criteria): array
    {
        $filter = new BaseFilter([]);
        $filter->setCriteria($criteria);
        $filter->withLimit(100);
        $list = (new WidgetService)->getList($filter);

        return array_map(fn ($i) => $i->title, $list->items);
    }

    public function test_equality_criterion_filters(): void
    {
        $this->assertSame(['A', 'C'], $this->titlesWithCriteria(['status' => 'completed']));
    }

    public function test_multiple_criteria_combine(): void
    {
        $this->assertSame(['C'], $this->titlesWithCriteria(['status' => 'completed', 'project_id' => 2]));
    }

    public function test_date_range_criteria(): void
    {
        $this->assertSame(['B'], $this->titlesWithCriteria(['due_date_from' => '2026-02-01', 'due_date_to' => '2026-02-28']));
        $this->assertSame(['B', 'C'], $this->titlesWithCriteria(['due_date_from' => '2026-02-01']));
    }

    public function test_empty_criteria_values_are_ignored(): void
    {
        $this->assertCount(3, $this->titlesWithCriteria(['status' => '', 'project_id' => null]));
        $this->assertCount(3, $this->titlesWithCriteria([]));
    }
}

class WidgetModel extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];
}

class WidgetService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return WidgetModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        throw new \LogicException('not used');
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return new WidgetListing($model->title);
    }
}

class WidgetListing extends \LaraSlice\Core\Base\BaseListingBusinessObject
{
    public function __construct(public ?string $title = null) {}
}
