<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Routing\RouteTruthIndex;
use LaraSlice\Tests\TestCase;

class SliceRouteTruthCommandTest extends TestCase
{
    public function test_route_truth_index_includes_registered_routes(): void
    {
        $payload = app(RouteTruthIndex::class)->build();

        $this->assertArrayHasKey('routes', $payload);
        $this->assertGreaterThan(0, $payload['route_count']);
        $this->assertArrayHasKey('uri', $payload['routes'][0]);
    }

    public function test_slice_route_truth_command_emits_json(): void
    {
        $this->artisan('slice:route-truth', ['--output' => sys_get_temp_dir().'/laraslice-route-truth-test.json'])
            ->expectsOutputToContain('route(s) to')
            ->assertSuccessful();

        $json = file_get_contents(sys_get_temp_dir().'/laraslice-route-truth-test.json');
        $this->assertStringContainsString('routes', $json);
        @unlink(sys_get_temp_dir().'/laraslice-route-truth-test.json');
    }

    public function test_slice_route_truth_path_filter(): void
    {
        $filtered = app(RouteTruthIndex::class)->build('admin/users');
        $full = app(RouteTruthIndex::class)->build();

        $this->assertLessThan($full['route_count'], $filtered['route_count']);
        foreach ($filtered['routes'] as $entry) {
            $this->assertStringStartsWith('admin/users', $entry['uri']);
        }
    }
}
