<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Security\Access;

/**
 * Declared record actions (L3): a slice's Heart may declare
 * record-level workflow actions beyond CRUD under `ui.actions`:
 *
 *   ui:
 *     actions:
 *       - {name: duplicate, label: Duplicate, type: duplicate}
 *       - {name: archive, label: Archive, type: transition,
 *          field: status, value: archived, confirm: "Archive?"}
 *
 * Types in v1: `duplicate` (copy the record + repeater children;
 * gated on the slice's create permission) and `transition`
 * (set one declared column to one declared value; gated on
 * update). Rows expose the actions through the directory
 * payload (urlsFor); a single framework endpoint executes them
 * (RecordActionController), so existing slices adopt actions by
 * declaration alone — no route regeneration.
 *
 * v2 adds: `link` (a plain navigation item; the declaration's
 * `url` may use {id}, {show}, {edit}, {public} and any record
 * attribute like {hash}; `new_tab: true` opens a new tab —
 * gated on view), `copy` (same URL building, but the item
 * copies the URL to the clipboard — gated on view), `send`
 * (mails the record's public/show link to the record's
 * client email — gated on update) and `method` (calls a
 * named method on the slice's own service with (id, user) —
 * gated on update). A slice declares `ui.public_link:
 * {column: hash}` to expose the record at
 * laraslice.public_record with the column's value as token.
 */
final class RecordActions
{
    /**
     * Normalize a manifest's declared actions.
     *
     * @param  array<string, mixed>  $raw  raw manifest data
     * @return list<array<string, mixed>>
     */
    public static function declaredActions(array $raw): array
    {
        $out = [];
        foreach (($raw['ui']['actions'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['name']) || empty($entry['type'])) {
                continue;
            }
            $type = (string) $entry['type'];
            if ($type === 'transition' && (empty($entry['field']) || ! array_key_exists('value', $entry))) {
                continue;
            }
            if (($type === 'link' || $type === 'copy') && empty($entry['url'])) {
                continue;
            }
            if ($type === 'method' && empty($entry['method'])) {
                continue;
            }
            if (! in_array($type, ['duplicate', 'transition', 'link', 'copy', 'send', 'method'], true)) {
                continue;
            }
            $out[] = [
                'name' => (string) $entry['name'],
                'label' => (string) ($entry['label'] ?? Str::headline((string) $entry['name'])),
                'type' => $type,
                'field' => isset($entry['field']) ? (string) $entry['field'] : null,
                'value' => $entry['value'] ?? null,
                'confirm' => isset($entry['confirm']) ? (string) $entry['confirm'] : null,
                'icon' => (string) ($entry['icon'] ?? ($type === 'duplicate' ? 'copy' : 'arrow-right')),
                'url' => isset($entry['url']) ? (string) $entry['url'] : null,
                'new_tab' => ! empty($entry['new_tab']),
                'method' => isset($entry['method']) ? (string) $entry['method'] : null,
            ];
        }

        return $out;
    }

    /**
     * The permission base for a slice, mirroring the base web
     * controller: the Heart's first declared permission base,
     * else the singular of the route prefix's last segment.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function permissionBase(array $raw, string $routePrefix): string
    {
        foreach (($raw['permissions'] ?? []) as $perm) {
            if (is_string($perm) && str_contains($perm, '.')) {
                return substr($perm, 0, (int) strpos($perm, '.'));
            }
        }
        $prefix = rtrim($routePrefix, '.');
        if (str_contains($prefix, '.')) {
            $parts = explode('.', $prefix);
            $prefix = (string) end($parts);
        }

        return Str::snake(Str::singular($prefix));
    }

    /**
     * Resolve the slice that owns a route prefix (post-R1 the
     * prefix is unique per slice): via its index route's
     * controller namespace matched against discovered slices.
     *
     * @return array{manifest: SliceManifest, raw: array<string, mixed>}|null
     */
    public static function sliceForRoutePrefix(string $routePrefix): ?array
    {
        $manager = app(SliceManager::class);
        $manager->discover();
        $route = Route::getRoutes()->getByName($routePrefix.'.index');
        if ($route === null) {
            return null;
        }
        $action = (string) ($route->getActionName() ?? '');
        $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
        $namespace = ltrim(Str::beforeLast($controller, '\\Controllers\\'), '\\');
        if ($namespace === $controller) {
            return null;
        }
        foreach ($manager->discoveredSlices() as $manifest) {
            if ($manifest->getNamespace() === $namespace) {
                return ['manifest' => $manifest, 'raw' => ManifestRepository::readFromSliceDir($manifest->path)];
            }
        }

        return null;
    }

    /**
     * Row payload for the directory: the declared actions the
     * current user may run, with execution URLs.
     *
     * @return list<array<string, mixed>>
     */
    public static function urlsFor(string $routePrefix, string|int $id): array
    {
        if (! Route::has('laraslice.record_action')) {
            return [];
        }
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return [];
        }
        $declared = self::declaredActions($resolved['raw']);
        if ($declared === []) {
            return [];
        }
        $user = auth()->user();
        if ($user === null) {
            return [];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $record = null;
        $recordLoaded = false;
        $out = [];
        foreach ($declared as $action) {
            $verb = match ($action['type']) {
                'duplicate' => 'create',
                'link', 'copy' => 'view',
                default => 'update',
            };
            $candidates = ["{$base}.{$verb}", "{$plural}.{$verb}", "{$base}.*", "{$plural}.*"];
            if (! Access::allows($user, $candidates)) {
                continue;
            }
            if ($action['type'] === 'link' || $action['type'] === 'copy') {
                if (! $recordLoaded) {
                    $recordLoaded = true;
                    $service = self::serviceFor($resolved['manifest']);
                    if ($service !== null) {
                        $ref = new \ReflectionMethod($service, 'getModelClass');
                        $ref->setAccessible(true);
                        $modelClass = $ref->invoke($service);
                        if (is_string($modelClass) && class_exists($modelClass)) {
                            $record = $modelClass::query()->find($id);
                        }
                    }
                }
                $url = self::buildActionUrl((string) $action['url'], $resolved['raw'], $routePrefix, $id, $record);
                if ($url === null) {
                    continue;
                }
                $out[] = [
                    'name' => $action['name'],
                    'label' => $action['label'],
                    'icon' => $action['icon'],
                    'confirm' => null,
                    'mode' => $action['type'],
                    'target' => $action['new_tab'] ? '_blank' : null,
                    'url' => $url,
                ];

                continue;
            }
            $out[] = [
                'name' => $action['name'],
                'label' => $action['label'],
                'icon' => $action['icon'],
                'confirm' => $action['confirm'],
                'mode' => 'post',
                'target' => null,
                'url' => route('laraslice.record_action', ['prefix' => $routePrefix, 'id' => $id, 'action' => $action['name']]),
            ];
        }

        return $out;
    }

    /**
     * The public (unauthenticated) URL for a record, when the
     * slice declares ui.public_link. The token is the value of
     * the declared column (default `hash`) — in ported systems
     * that is the source's own public-link hash.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function publicUrl(array $raw, string $routePrefix, mixed $record): ?string
    {
        $decl = $raw['ui']['public_link'] ?? null;
        if ($decl === null || $decl === false || $record === null) {
            return null;
        }
        $column = is_array($decl) ? (string) ($decl['column'] ?? 'hash') : 'hash';
        $token = $record->{$column} ?? null;
        if (! is_string($token) || $token === '' || ! Route::has('laraslice.public_record')) {
            return null;
        }

        return route('laraslice.public_record', ['prefix' => $routePrefix, 'token' => $token]);
    }

    /**
     * Substitute a link/copy action's URL template: {id},
     * {show}, {edit}, {public} and any record attribute
     * ({hash}, {invoice_number}, ...). Returns null when a
     * needed value is unavailable (the item is then hidden).
     *
     * @param  array<string, mixed>  $raw
     */
    private static function buildActionUrl(string $template, array $raw, string $routePrefix, string|int $id, mixed $record): ?string
    {
        $failed = false;
        $url = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function (array $m) use ($raw, $routePrefix, $id, $record, &$failed) {
            $key = strtolower($m[1]);
            $value = match ($key) {
                'id' => (string) $id,
                'show' => Route::has($routePrefix.'.show') ? route($routePrefix.'.show', $id) : null,
                'edit' => Route::has($routePrefix.'.edit') ? route($routePrefix.'.edit', $id) : null,
                'public' => self::publicUrl($raw, $routePrefix, $record),
                default => ($record !== null && isset($record->{$key})) ? (string) $record->{$key} : null,
            };
            if ($value === null || $value === '') {
                $failed = true;

                return '';
            }

            return $value;
        }, $template);

        return ($failed || $url === null || $url === '') ? null : $url;
    }

    /**
     * Execute one declared action. Returns [status, message, newId].
     *
     * @return array{status: int, message: string, new_id: int|string|null}
     */
    public static function execute(string $routePrefix, string|int $id, string $actionName, mixed $user): array
    {
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return ['status' => 404, 'message' => 'Unknown slice.', 'new_id' => null];
        }
        $action = null;
        foreach (self::declaredActions($resolved['raw']) as $candidate) {
            if ($candidate['name'] === $actionName) {
                $action = $candidate;
                break;
            }
        }
        if ($action === null) {
            return ['status' => 404, 'message' => 'Action not declared for this slice.', 'new_id' => null];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $verb = $action['type'] === 'duplicate' ? 'create' : 'update';
        if ($user === null || ! Access::allows($user, ["{$base}.{$verb}", "{$plural}.{$verb}", "{$base}.*", "{$plural}.*"])) {
            return ['status' => 403, 'message' => 'Access denied for this action.', 'new_id' => null];
        }
        $service = self::serviceFor($resolved['manifest']);
        if ($service === null) {
            return ['status' => 500, 'message' => 'Slice service not resolvable.', 'new_id' => null];
        }
        if ($action['type'] === 'duplicate') {
            $copy = $service->duplicateRecord($id);

            return $copy === null
                ? ['status' => 404, 'message' => 'Record not found.', 'new_id' => null]
                : ['status' => 200, 'message' => $action['label'].' complete.', 'new_id' => $copy->getKey()];
        }
        if ($action['type'] === 'method') {
            $method = (string) $action['method'];
            if (! method_exists($service, $method)) {
                return ['status' => 422, 'message' => "The slice service has no {$method}() method.", 'new_id' => null];
            }
            $result = $service->{$method}($id, $user);
            if (is_string($result)) {
                return ['status' => 200, 'message' => $result, 'new_id' => null];
            }
            if (is_array($result) && isset($result[0], $result[1])) {
                return ['status' => (int) $result[0], 'message' => (string) $result[1], 'new_id' => $result[2] ?? null];
            }

            return $result
                ? ['status' => 200, 'message' => $action['label'].' complete.', 'new_id' => null]
                : ['status' => 422, 'message' => $action['label'].' could not be applied.', 'new_id' => null];
        }
        if ($action['type'] === 'send') {
            return self::executeSend($resolved['raw'], $routePrefix, $service, $id, $action);
        }
        $ok = $service->transitionRecord($id, (string) $action['field'], $action['value']);

        return $ok
            ? ['status' => 200, 'message' => $action['label'].' complete.', 'new_id' => null]
            : ['status' => 422, 'message' => 'Transition could not be applied.', 'new_id' => null];
    }

    /**
     * The `send` action: mail the record's link to the email
     * the record carries (a direct email column, or the
     * client/user relation's email). The link is the public
     * URL when the slice declares one, else the show URL.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $action
     * @return array{status: int, message: string, new_id: null}
     */
    private static function executeSend(array $raw, string $routePrefix, BaseSliceService $service, string|int $id, array $action): array
    {
        $ref = new \ReflectionMethod($service, 'getModelClass');
        $ref->setAccessible(true);
        $modelClass = $ref->invoke($service);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return ['status' => 500, 'message' => 'Send: model class not resolvable.', 'new_id' => null];
        }
        $record = $modelClass::query()->find($id);
        if ($record === null) {
            return ['status' => 404, 'message' => 'Record not found.', 'new_id' => null];
        }
        $recipient = null;
        foreach (['client_email', 'email'] as $column) {
            if (! empty($record->{$column})) {
                $recipient = (string) $record->{$column};
                break;
            }
        }
        if ($recipient === null) {
            foreach (['client', 'user'] as $relation) {
                if (method_exists($record, $relation)) {
                    $related = $record->{$relation};
                    if ($related !== null && ! empty($related->email)) {
                        $recipient = (string) $related->email;
                        break;
                    }
                }
            }
        }
        if ($recipient === null) {
            return ['status' => 422, 'message' => 'No recipient email on this record.', 'new_id' => null];
        }
        $url = self::publicUrl($raw, $routePrefix, $record)
            ?? (Route::has($routePrefix.'.show') ? route($routePrefix.'.show', $record->getKey()) : null);
        if ($url === null) {
            return ['status' => 422, 'message' => 'No link available for this record.', 'new_id' => null];
        }
        $number = null;
        foreach (['invoice_number', 'estimate_number', 'contract_number', 'order_number', 'title', 'name'] as $column) {
            if (! empty($record->{$column})) {
                $number = (string) $record->{$column};
                break;
            }
        }
        $subject = Str::headline(Str::singular(str_replace('_', ' ', self::permissionBase($raw, $routePrefix))))
            .($number !== null ? ' '.$number : ' #'.$record->getKey());
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Hello,\n\nYour {$subject} is available here:\n{$url}\n",
                function ($message) use ($recipient, $subject) {
                    $message->to($recipient)->subject($subject);
                }
            );
        } catch (\Throwable $e) {
            return ['status' => 422, 'message' => 'Mail failed: '.$e->getMessage(), 'new_id' => null];
        }

        return ['status' => 200, 'message' => "Sent to {$recipient}.", 'new_id' => null];
    }

    private static function serviceFor(SliceManifest $manifest): ?BaseSliceService
    {
        $namespace = $manifest->getNamespace();
        $singular = Str::studly(Str::singular($manifest->name));
        $candidates = ["{$namespace}\\Services\\{$singular}SliceService"];
        $servicesPath = $manifest->getServicesPath();
        if ($servicesPath !== null) {
            foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $file) {
                $candidates[] = $namespace.'\\Services\\'.basename($file, '.php');
            }
        }
        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                $service = app($class);
                if ($service instanceof BaseSliceService) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * Execute a native bulk action (L4) for a slice resolved
     * by its route prefix. v1 supports 'delete' (the
     * directory's declared bulk handle), gated on the
     * slice's delete permission. Returns [status, message,
     * affected].
     *
     * @param  array<int, mixed>  $ids
     * @return array{0: int, 1: string, 2: int}
     */
    public static function executeBulk(string $routePrefix, string $action, array $ids, mixed $user): array
    {
        if ($action !== 'delete') {
            return [404, 'Unknown bulk action.', 0];
        }
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return [404, 'Unknown slice.', 0];
        }
        $ids = array_values(array_filter($ids, static fn ($id) => is_int($id) || (is_string($id) && $id !== '')));
        if ($ids === []) {
            return [422, 'No records selected.', 0];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $allowed = false;
        foreach (["{$base}.delete", "{$plural}.delete", "{$base}.*", "{$plural}.*"] as $candidate) {
            if (Access::allows($user, $candidate)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            return [403, 'You are not allowed to delete these records.', 0];
        }
        $service = self::serviceFor($resolved['manifest']);
        if ($service === null) {
            return [500, 'The slice service could not be resolved.', 0];
        }
        $deleted = $service->bulkDelete($ids);

        return [200, "Deleted {$deleted} record(s).", $deleted];
    }
}
