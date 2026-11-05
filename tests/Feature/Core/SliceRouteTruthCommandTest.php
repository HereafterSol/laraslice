<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Routing\RouteTruthIndex;
use LaraSlice\Tests\TestCase;

class SliceRouteTruthCommandTest extends TestCase
{
    public function test_route_truth_index_includes_registered_routes(): void
    {
        $payload = app(RouteTruthIndex::class)->build();

        $this->assertRouteTruthPayloadShape($payload);
        $this->assertGreaterThan(0, $payload['route_count']);
    }

    public function test_route_truth_payload_matches_routing_truth_contract(): void
    {
        $payload = app(RouteTruthIndex::class)->build();

        $this->assertNotEmpty($payload['generated_at']);
        $this->assertSame($payload['route_count'], count($payload['routes']));

        $withSlice = array_filter(
            $payload['routes'],
            static fn (array $entry): bool => $entry['slice_id'] !== null && $entry['slice_id'] !== ''
        );
        $this->assertNotEmpty($withSlice, 'Expected at least one controller-backed route with slice_id');
    }

    public function test_slice_route_truth_command_emits_json(): void
    {
        $outputPath = sys_get_temp_dir().'/laraslice-route-truth-test.json';

        $this->artisan('slice:route-truth', ['--output' => $outputPath])
            ->expectsOutputToContain('route(s) to')
            ->assertSuccessful();

        $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertRouteTruthPayloadShape($payload);
        @unlink($outputPath);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRouteTruthPayloadShape(array $payload): void
    {
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('route_count', $payload);
        $this->assertArrayHasKey('routes', $payload);
        $this->assertIsArray($payload['routes']);

        if ($payload['routes'] === []) {
            return;
        }

        $entry = $payload['routes'][0];
        $this->assertArrayHasKey('uri', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('slice_id', $entry);
        $this->assertArrayHasKey('methods', $entry);
        $this->assertIsArray($entry['methods']);
    }
}
