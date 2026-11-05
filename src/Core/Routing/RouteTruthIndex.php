<?php

namespace LaraSlice\Core\Routing;

use Illuminate\Support\Facades\Route;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Machine-readable route index for agents and MCP — uri, name, and owning slice.
 * Prefer this over `route:list` for deep links (see ROUTING-TRUTH-ALIGNMENT.md).
 */
final class RouteTruthIndex
{
    public function __construct(private SliceManager $manager) {}

    /**
     * @return array{
     *     generated_at: string,
     *     route_count: int,
     *     routes: list<array{uri: string, name: string|null, slice_id: string|null, methods: list<string>}>
     * }
     */
    public function build(?string $pathPrefix = null): array
    {
        $this->manager->discover(true);
        $namespaceToSliceId = $this->namespaceToSliceIdMap();
        $entries = [];

        foreach (Route::getRoutes() as $route) {
            $uri = trim($route->uri(), '/');
            if ($pathPrefix !== null && $pathPrefix !== '') {
                $prefix = trim($pathPrefix, '/');
                if ($prefix !== '' && ! str_starts_with($uri, $prefix)) {
                    continue;
                }
            }

            $entries[] = [
                'uri' => $uri,
                'name' => $route->getName(),
                'slice_id' => $this->resolveSliceId($route, $namespaceToSliceId),
                'methods' => array_values($route->methods()),
            ];
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => [$a['uri'], $a['name'] ?? ''] <=> [$b['uri'], $b['name'] ?? '']
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'route_count' => count($entries),
            'routes' => $entries,
        ];
    }

    /**
     * @return array<string, string> controller namespace prefix => slice_id
     */
    private function namespaceToSliceIdMap(): array
    {
        $map = [];
        foreach ($this->manager->getActiveSlices() as $slice) {
            $namespace = method_exists($slice, 'getNamespace')
                ? $slice->getNamespace()
                : (string) ($slice->raw['namespace'] ?? $slice->namespace ?? '');
            if ($namespace === '') {
                continue;
            }
            $map[rtrim($namespace, '\\')] = (string) ($slice->canonical_id ?? $slice->name);
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $namespaceToSliceId
     */
    private function resolveSliceId(\Illuminate\Routing\Route $route, array $namespaceToSliceId): ?string
    {
        $action = (string) ($route->getActionName() ?? '');
        if ($action === '' || $action === 'Closure') {
            return null;
        }

        $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
        if ($controller === false || $controller === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach ($namespaceToSliceId as $namespace => $sliceId) {
            if (str_starts_with($controller, $namespace) && strlen($namespace) > $bestLen) {
                $best = $sliceId;
                $bestLen = strlen($namespace);
            }
        }

        return $best;
    }
}
