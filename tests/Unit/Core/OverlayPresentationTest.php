<?php

namespace LaraSlice\Tests\Unit\Core;

use Illuminate\Http\Request;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use PHPUnit\Framework\TestCase;

/**
 * F7: URL-bound overlay presentation. The YAML declares
 * ui.presentation.{create,edit,show} as page|drawer|modal; the base
 * controller resolves them (invalid values fall back to page) and
 * answers overlay fetches of the SAME canonical URL with the
 * fragment layout instead of the app layout.
 */
class OverlayPresentationTest extends TestCase
{
    public function test_presentation_modes_resolve_and_normalize(): void
    {
        $c = new PresentationProbeController;
        $c->fakeUi = ['presentation' => ['create' => 'drawer', 'edit' => 'modal', 'show' => 'banana']];
        $this->assertSame(['create' => 'drawer', 'edit' => 'modal', 'show' => 'page'], $c->modes());
    }

    public function test_drawer_size_resolves_declared_width_and_defaults_to_lg(): void
    {
        $c = new PresentationProbeController;
        $c->fakeUi = ['presentation' => ['create' => 'drawer', 'drawer_width' => 'xl']];
        $this->assertSame('xl', $c->drawerSize());

        $c->fakeUi = ['presentation' => ['create' => 'drawer', 'drawer_width' => 'huge']];
        $this->assertSame('lg', $c->drawerSize());

        $c->fakeUi = [];
        $this->assertSame('lg', $c->drawerSize());
    }

    public function test_presentation_modes_default_to_page(): void
    {
        $c = new PresentationProbeController;
        $this->assertSame(['create' => 'page', 'edit' => 'page', 'show' => 'page'], $c->modes());
    }

    public function test_generated_index_intercepts_overlay_form_submits(): void
    {
        // The overlay submit handler ships in the generated index
        // template: it posts the form in place, and it must read the
        // target via getAttribute('action') — generated forms carry
        // submit buttons named "action" that shadow form.action.
        $generator = file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('data-ls-overlay-errors', $generator);
        $this->assertStringContainsString("form.getAttribute('action')", $generator);
        $this->assertStringNotContainsString('fetch(form.action', $generator);
    }

    public function test_overlay_request_gets_fragment_layout(): void
    {
        $c = new PresentationProbeController;
        $plain = Request::create('/leaves/1', 'GET');
        $this->assertSame([], $c->layoutData($plain));

        $overlay = Request::create('/leaves/1', 'GET', [], [], [], ['HTTP_X_LARASLICE_OVERLAY' => 'drawer']);
        $this->assertSame(['overlayLayout' => 'laraslice::layouts.fragment'], $c->layoutData($overlay));
    }
}

class PresentationProbeController extends BaseSliceWebController
{
    public array $fakeUi = [];

    protected function getSliceUiConfig(): array
    {
        return $this->fakeUi;
    }

    public function modes(): array
    {
        return $this->presentationModes();
    }

    public function drawerSize(): string
    {
        return $this->presentationDrawerSize();
    }

    public function layoutData(Request $request): array
    {
        return $this->overlayLayoutData($request);
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
        return 'probe.';
    }
}
