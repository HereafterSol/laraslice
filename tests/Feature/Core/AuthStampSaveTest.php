<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Tests\TestCase;

/**
 * Declared auth stamps (Comparative Fix Programme Phase C):
 * a Heart field carrying `stamp: auth` is filled from the
 * acting user on create when the client left it empty — the
 * mechanism that lets a hub tab post a reply with only its
 * body while the author is recorded server-side. An explicitly
 * submitted value always wins, and updates never re-stamp.
 */
class AuthStampSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('probe_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        DB::table('probe_users')->insert([['id' => 7, 'name' => 'Author'], ['id' => 9, 'name' => 'Other']]);
        Schema::create('probe_replies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ticket_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('message')->nullable();
        });

        Auth::login(AuthStampProbeUser::find(7));
    }

    public function test_empty_author_is_stamped_from_the_acting_user(): void
    {
        $form = AuthStampProbeForm::fromArray(['ticket_id' => 3, 'message' => 'hello']);
        $id = (new AuthStampProbeService)->save($form);

        $this->assertSame(7, (int) DB::table('probe_replies')->find($id)->user_id);
    }

    public function test_an_explicit_author_always_wins(): void
    {
        $form = AuthStampProbeForm::fromArray(['ticket_id' => 3, 'user_id' => 9, 'message' => 'hello']);
        $id = (new AuthStampProbeService)->save($form);

        $this->assertSame(9, (int) DB::table('probe_replies')->find($id)->user_id);
    }

    public function test_an_update_never_restamps_the_author(): void
    {
        $form = AuthStampProbeForm::fromArray(['ticket_id' => 3, 'message' => 'hello']);
        $id = (new AuthStampProbeService)->save($form);

        Auth::login(AuthStampProbeUser::find(9));
        $edit = AuthStampProbeForm::fromArray(['id' => $id, 'ticket_id' => 3, 'message' => 'edited']);
        (new AuthStampProbeService)->save($edit);

        $this->assertSame(7, (int) DB::table('probe_replies')->find($id)->user_id);
    }
}

class AuthStampProbeUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'probe_users';

    protected $guarded = [];

    public $timestamps = false;
}

class AuthStampProbeForm extends BaseFormBusinessObject
{
    public string|int|null $id = null;

    public ?int $ticket_id = null;

    public ?int $user_id = null;

    public ?string $message = null;
}

class AuthStampProbeModel extends Model
{
    protected $table = 'probe_replies';

    protected $guarded = [];

    public $timestamps = false;
}

class AuthStampProbeService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return AuthStampProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        return AuthStampProbeForm::fromArray($model->toArray());
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return AuthStampProbeForm::fromArray($model->toArray());
    }

    protected function ownManifest(): array
    {
        return [
            'fields' => [
                'user_id' => ['stamp' => 'auth'],
            ],
        ];
    }

    protected function validate(IBusinessObject $form): void {}
}
