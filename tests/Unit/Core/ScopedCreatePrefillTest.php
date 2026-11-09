<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use PHPUnit\Framework\TestCase;

/**
 * Scoped-create prefill: hub tabs link to create pages with the
 * parent foreign key in the query string (…/create?ticket_id=1).
 * The base controller seeds the fresh form from those parameters
 * so the record is stored against the parent the user came from,
 * not orphaned with an empty picker.
 */
class ScopedCreatePrefillTest extends TestCase
{
    public function test_query_parameters_seed_matching_form_properties_with_type_casts(): void
    {
        $form = new ScopedCreateProbeForm;
        (new ScopedCreateProbeController)->seed($form, [
            'ticket_id' => '5',
            'message' => 'hello',
            'rating' => '4.5',
            'pinned' => '1',
        ]);

        $this->assertSame(5, $form->ticket_id);
        $this->assertSame('hello', $form->message);
        $this->assertSame(4.5, $form->rating);
        $this->assertTrue($form->pinned);
    }

    public function test_unknown_and_non_scalar_query_keys_are_ignored(): void
    {
        $form = new ScopedCreateProbeForm;
        (new ScopedCreateProbeController)->seed($form, [
            'not_a_field' => 'x',
            'ticket_id' => ['nested' => 'array'],
            '_token' => 'abc',
        ]);

        $this->assertNull($form->ticket_id);
        $this->assertSame('', $form->message);
    }

    public function test_private_properties_are_never_seeded(): void
    {
        $form = new ScopedCreateProbeForm;
        (new ScopedCreateProbeController)->seed($form, ['secret' => 'nope']);

        $this->assertSame('unset', $form->revealSecret());
    }

    public function test_create_action_wires_the_seeding(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('seedFormFromQuery($form, request()->query())', $controller);
    }
}

class ScopedCreateProbeForm
{
    public ?int $ticket_id = null;

    public string $message = '';

    public ?float $rating = null;

    public bool $pinned = false;

    private string $secret = 'unset';

    public function revealSecret(): string
    {
        return $this->secret;
    }
}

class ScopedCreateProbeController extends BaseSliceWebController
{
    public function seed(object $form, array $query): void
    {
        $this->seedFormFromQuery($form, $query);
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        throw new \LogicException('not used');
    }

    protected function getFormClass(): string
    {
        return \stdClass::class;
    }

    protected function getFilterClass(): string
    {
        return \stdClass::class;
    }

    protected function getViewPrefix(): string
    {
        return 'probe::';
    }

    protected function getRoutePrefix(): string
    {
        return 'probe';
    }
}
