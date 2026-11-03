<?php

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Support\RecordActions;

/**
 * Unauthenticated read-only view of one record, for slices
 * whose Heart declares `ui.public_link`. The token is the
 * value of the declared column (default `hash`) — in ported
 * systems that is the source's own public-link hash, so the
 * links a source system already mailed keep working. No
 * declaration, no token match → 404; the page exposes the
 * detail composition and child tables only, never actions.
 */
final class PublicRecordController
{
    public function __invoke(Request $request, string $prefix, string $token): View
    {
        $resolved = RecordActions::sliceForRoutePrefix($prefix);
        if ($resolved === null) {
            abort(404);
        }
        $raw = $resolved['raw'];
        $decl = $raw['ui']['public_link'] ?? null;
        if ($decl === null || $decl === false) {
            abort(404);
        }
        $column = is_array($decl) ? (string) ($decl['column'] ?? 'hash') : 'hash';

        $manifest = $resolved['manifest'];
        $namespace = $manifest->getNamespace();
        $singular = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($manifest->name));
        $serviceClass = "{$namespace}\\Services\\{$singular}SliceService";
        if (! class_exists($serviceClass)) {
            abort(404);
        }
        $service = app($serviceClass);
        $ref = new \ReflectionMethod($service, 'getModelClass');
        $ref->setAccessible(true);
        $modelClass = $ref->invoke($service);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            abort(404);
        }
        $probe = new $modelClass;
        if (! $probe->getConnection()->getSchemaBuilder()->hasColumn($probe->getTable(), $column)) {
            abort(404);
        }
        $record = $modelClass::query()->where($column, $token)->first();
        if ($record === null) {
            abort(404);
        }

        $rows = DirectoryUi::detailRows($manifest->name, $record);

        $children = [];
        foreach (($raw['relations'] ?? []) as $relation) {
            if (! is_array($relation) || ($relation['type'] ?? null) !== 'hasMany') {
                continue;
            }
            $handle = (string) ($relation['handle'] ?? '');
            if ($handle === '' || ! method_exists($record, $handle)) {
                continue;
            }
            try {
                $items = $record->{$handle}()->limit(100)->get();
            } catch (\Throwable $e) {
                continue;
            }
            if ($items->isEmpty()) {
                continue;
            }
            $hidden = ['id', 'created_at', 'updated_at', 'deleted_at', $record->getForeignKey()];
            $columns = [];
            foreach ($items->first()->getAttributes() as $key => $value) {
                if (in_array($key, $hidden, true) || is_array($value) || is_object($value)) {
                    continue;
                }
                $columns[] = $key;
            }
            $children[] = [
                'label' => (string) ($relation['label'] ?? \Illuminate\Support\Str::headline($handle)),
                'columns' => $columns,
                'rows' => $items,
            ];
        }

        $title = (string) ($raw['navigation']['label'] ?? $raw['title'] ?? $manifest->name);

        return view('laraslice::public.record', [
            'title' => $title,
            'record' => $record,
            'rows' => $rows,
            'children' => $children,
        ]);
    }
}
