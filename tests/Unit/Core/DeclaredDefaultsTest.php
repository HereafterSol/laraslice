<?php

namespace LaraSlice\Tests\Unit\Core;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use PHPUnit\Framework\TestCase;

/**
 * Declared create defaults + computed fields (Comparative Fix
 * Programme Phase A): a create that leaves a defaulted field
 * empty stores the manifest's declared default — never an
 * accidental NULL status — and a field with a `computed:`
 * spec is derived server-side on save. Both read the slice's
 * own manifest, so a Heart edit is the whole fix.
 */
class DeclaredDefaultsTest extends TestCase
{
    public function test_empty_status_gets_the_declared_default(): void
    {
        $form = new DeclaredDefaultsProbeForm;
        (new DeclaredDefaultsProbeService)->defaults($form);

        $this->assertSame('pending', $form->status);
    }

    public function test_blank_string_submission_also_gets_the_default(): void
    {
        $form = new DeclaredDefaultsProbeForm;
        $form->status = '';
        (new DeclaredDefaultsProbeService)->defaults($form);

        $this->assertSame('pending', $form->status);
    }

    public function test_an_explicit_value_always_wins(): void
    {
        $form = new DeclaredDefaultsProbeForm;
        $form->status = 'approved';
        (new DeclaredDefaultsProbeService)->defaults($form);

        $this->assertSame('approved', $form->status);
    }

    public function test_defaults_are_type_coerced_to_the_bo_property(): void
    {
        $form = new DeclaredDefaultsProbeForm;
        (new DeclaredDefaultsProbeService)->defaults($form);

        $this->assertSame(3, $form->attempts);
    }

    public function test_save_wires_declared_defaults_and_computed_fields(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceService.php');
        $this->assertStringContainsString('applyDeclaredDefaults($form)', $service);
        $this->assertStringContainsString('applyComputedFields($model)', $service);
    }

    public function test_hours_between_computes_decimal_hours(): void
    {
        $model = new DeclaredDefaultsProbeModel;
        $model->setAttribute('start_time', '2026-11-06 09:00:00');
        $model->setAttribute('end_time', '2026-11-06 17:30:00');
        $model->setAttribute('total_hours', null);
        (new DeclaredDefaultsProbeService)->computed($model);

        $this->assertSame(8.5, $model->getAttribute('total_hours'));
    }

    public function test_hours_between_leaves_unparseable_endpoints_untouched(): void
    {
        $model = new DeclaredDefaultsProbeModel;
        $model->setAttribute('start_time', '2026-11-06 09:00:00');
        $model->setAttribute('end_time', null);
        $model->setAttribute('total_hours', null);
        (new DeclaredDefaultsProbeService)->computed($model);

        $this->assertNull($model->getAttribute('total_hours'));
    }
}

class DeclaredDefaultsProbeForm extends BaseFormBusinessObject
{
    public ?string $status = null;

    public ?int $attempts = null;
}

class DeclaredDefaultsProbeModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class DeclaredDefaultsProbeService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return DeclaredDefaultsProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        return new DeclaredDefaultsProbeForm;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return new DeclaredDefaultsProbeForm;
    }

    protected function ownManifest(): array
    {
        return [
            'fields' => [
                'status' => ['default' => 'pending'],
                'attempts' => ['default' => '3'],
                'total_hours' => ['computed' => 'hours_between(start_time,end_time)'],
            ],
        ];
    }

    public function defaults(IBusinessObject $form): void
    {
        $this->applyDeclaredDefaults($form);
    }

    public function computed(Model $model): void
    {
        $this->applyComputedFields($model);
    }
}
