<?php

namespace LaraSlice\Tests\Unit\Blueprint;

use LaraSlice\Blueprint\BlueprintValidationException;
use LaraSlice\Blueprint\BlueprintValidator;
use PHPUnit\Framework\TestCase;

class BlueprintValidatorTest extends TestCase
{
    public function test_it_accepts_a_multi_model_parent_child_blueprint(): void
    {
        $blueprint = (new BlueprintValidator)->validate($this->serviceDeskBlueprint());

        $this->assertSame('service_desk', $blueprint['models'][0]['handle']);
        $this->assertCount(2, $blueprint['models']);
    }

    public function test_it_rejects_duplicate_field_handles_and_unknown_relation_targets(): void
    {
        $blueprint = $this->serviceDeskBlueprint();
        $blueprint['models'][0]['fields'][] = ['handle' => 'name', 'type' => 'string'];
        $blueprint['models'][0]['relations'][0]['model'] = 'missing_model';

        try {
            (new BlueprintValidator)->validate($blueprint);
            $this->fail('Expected blueprint errors.');
        } catch (BlueprintValidationException $exception) {
            $this->assertStringContainsString("duplicates field 'name'", $exception->getMessage());
            $this->assertStringContainsString('must reference a model handle', $exception->getMessage());
        }
    }

    public function test_it_rejects_a_relation_foreign_key_that_is_not_on_the_related_model(): void
    {
        $blueprint = $this->serviceDeskBlueprint();
        $blueprint['models'][0]['relations'][0]['foreign_key'] = 'missing_id';

        $this->expectException(BlueprintValidationException::class);
        (new BlueprintValidator)->validate($blueprint);
    }

    public function test_it_rejects_unreachable_models_instead_of_planning_orphan_tables(): void
    {
        $blueprint = $this->serviceDeskBlueprint();
        $blueprint['models'][] = [
            'handle' => 'asset',
            'table' => 'assets',
            'fields' => [['handle' => 'filename', 'type' => 'string']],
        ];

        $this->expectException(BlueprintValidationException::class);
        (new BlueprintValidator)->validate($blueprint);
    }

    public function test_it_rejects_unknown_schema_keys_instead_of_silently_ignoring_them(): void
    {
        $blueprint = $this->serviceDeskBlueprint();
        $blueprint['models'][0]['fields'][0]['searchable'] = true;

        $this->expectException(BlueprintValidationException::class);
        (new BlueprintValidator)->validate($blueprint);
    }

    private function serviceDeskBlueprint(): array
    {
        return [
            'schema_version' => 1,
            'name' => 'Service Desk',
            'handle' => 'service_desk',
            'models' => [
                [
                    'handle' => 'service_desk',
                    'table' => 'service_desks',
                    'root' => true,
                    'fields' => [['handle' => 'name', 'type' => 'string', 'required' => true]],
                    'relations' => [[
                        'name' => 'tickets',
                        'type' => 'hasMany',
                        'model' => 'ticket',
                        'foreign_key' => 'service_desk_id',
                    ]],
                ],
                [
                    'handle' => 'ticket',
                    'table' => 'tickets',
                    'fields' => [
                        ['handle' => 'service_desk_id', 'type' => 'foreign_id'],
                        ['handle' => 'subject', 'type' => 'string'],
                    ],
                    'relations' => [[
                        'name' => 'service_desk',
                        'type' => 'belongsTo',
                        'model' => 'service_desk',
                        'foreign_key' => 'service_desk_id',
                    ]],
                ],
            ],
        ];
    }
}
