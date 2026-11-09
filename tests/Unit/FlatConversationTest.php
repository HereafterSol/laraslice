<?php

namespace LaraSlice\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * The flat conversation mode's row assembly is exercised
 * end-to-end in the port verification; here the declaration
 * contract itself is pinned: the view model must branch on
 * `flat` and the insert helper must exist with the mirror
 * behaviour.
 */
class FlatConversationTest extends TestCase
{
    public function test_flat_mode_machinery_exists(): void
    {
        $controller = \LaraSlice\Core\Base\BaseSliceWebController::class;
        $this->assertTrue(method_exists($controller, 'flatConversations'));
        $this->assertTrue(method_exists($controller, 'insertFlatMessage'));
        $method = new ReflectionMethod($controller, 'insertFlatMessage');
        $this->assertSame(['decl', 'me', 'other', 'body'], array_map(fn ($p) => $p->getName(), $method->getParameters()));
    }

    /**
     * Regression pin (flagship audit, Phase 1 item 3): on a table
     * whose NOT NULL notification flag carries NO database default
     * — exactly what the pre-fix dataset converter produced for
     * users_chat on SQLite — the flat insert must still persist,
     * by setting the flag explicitly. On MariaDB the schema
     * default masked the omission.
     */
    public function test_flat_insert_sets_notification_flag_when_column_has_no_default(): void
    {
        Schema::create('users_chat', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_one');
            $table->unsignedInteger('user_id');
            $table->text('message')->nullable();
            $table->unsignedInteger('from')->nullable();
            $table->unsignedInteger('to')->nullable();
            $table->string('message_seen');
            $table->boolean('notification_sent');
        });

        $modelClass = get_class(new class extends Model
        {
            protected $table = 'users_chat';

            protected $guarded = [];

            public $timestamps = false;
        });

        $service = new class($modelClass) extends BaseSliceService
        {
            public function __construct(private string $probeModelClass) {}

            protected function getModelClass(): string
            {
                return $this->probeModelClass;
            }

            protected function mapToForm(Model $model): IBusinessObject
            {
                return new class extends BaseFormBusinessObject {};
            }

            protected function mapToListing(Model $model): IBusinessObject
            {
                return new class extends BaseFormBusinessObject {};
            }
        };

        $controller = new class($service) extends BaseSliceWebController
        {
            public function __construct(private IFormDataService&IListingDataService $probeService) {}

            protected function getService(): IFormDataService&IListingDataService
            {
                return $this->probeService;
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
        };

        $insert = new ReflectionMethod($controller, 'insertFlatMessage');
        $insert->setAccessible(true);
        $insert->invoke($controller, ['from' => 'from', 'to' => 'to', 'body' => 'message', 'seen' => 'message_seen'], 23, 15, 'hello board');

        $row = DB::table('users_chat')->first();
        $this->assertNotNull($row, 'flat message must persist even without a column default');
        $this->assertSame('hello board', $row->message);
        $this->assertSame('no', $row->message_seen);
        $this->assertSame(1, (int) $row->notification_sent);
    }
}
