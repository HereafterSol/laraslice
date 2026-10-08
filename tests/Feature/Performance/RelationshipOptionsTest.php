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
}
