<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;

/**
 * Seat 2 — the record workplace composer. Every slice's
 * record page gets the same furniture the source gives
 * every DocType for free: tabs (Details / Activities /
 * Notes / Connections), a right rail (identity, assign,
 * attachments, tags, share, audit) and prev/next
 * navigation. Everything composed here is REAL data:
 * connections are live inbound-link queries across the
 * registry, activities are the record's audit trail,
 * and notes / attachments / tags / shares live in the
 * generic laraslice_record_* stores.
 */
class RecordWorkplace
{
    /**
     * Inbound link fields pointing AT $sliceName, found
     * by scanning every other slice's manifest. Two link
     * shapes: a declared relation (relation: lead ->
     * Leads) and a dynamic link (options_from:
     * slice_records:<via>, where the via field's stored
     * value names the target slice at runtime).
     *
     * @param  array<string, array>  $manifestsBySlice  slice name => raw manifest
     * @return array<int, array{slice: string, field: string, via: ?string}>
     */
    /**
     * How many records across all slices link TO this record
     * (the same discovery the workplace Connections tab uses,
     * including Dynamic-Link repeater rows). The delete guard
     * (D-081) refuses to orphan these references. Discovery
     * failures read as zero — the guard never invents links.
     */
    public static function inboundLinkCount(SliceManager $manager, string $sliceName, mixed $id): int
    {
        try {
            $total = 0;
            foreach (self::connections($manager, $sliceName, $id) as $group) {
                $total += (int) ($group['count'] ?? 0);
            }

            return $total;
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function inboundFields(array $manifestsBySlice, string $sliceName): array
    {
        $out = [];
        $relationKey = Str::snake(Str::singular($sliceName));
        foreach ($manifestsBySlice as $otherSlice => $manifest) {
            if ($otherSlice === $sliceName || ! is_array($manifest)) {
                continue;
            }
            foreach (SliceRecordOptions::manifestFieldsOf($manifest) as $fieldName => $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                $relation = $definition['relation'] ?? null;
                if (is_string($relation) && $relation !== ''
                    && Str::studly(Str::plural($relation)) === $sliceName) {
                    $out[] = ['slice' => (string) $otherSlice, 'field' => (string) $fieldName, 'via' => null];
                    continue;
                }
                $from = $definition['options_from'] ?? null;
                if (is_string($from) && str_starts_with($from, 'slice_records:')) {
                    $out[] = ['slice' => (string) $otherSlice, 'field' => (string) $fieldName, 'via' => substr($from, strlen('slice_records:'))];
                }
            }
        }

        return $out;
    }

    /** Compose the full workplace payload for one record. Never throws. */
    public static function compose(string $sliceName, array $manifest, object $record): array
    {
        $empty = [
            'slice' => $sliceName, 'id' => $record->id ?? null,
            'identity' => [], 'assignment' => null, 'audit' => [],
            'connections' => [], 'activity' => [], 'notes' => [],
            'attachments' => [], 'tags' => [], 'shares' => [],
            'users' => [], 'prev' => null, 'next' => null,
            'single' => ! empty($manifest['ui']['single']),
        ];
        try {
            $manager = app(SliceManager::class);
            $id = $record->id ?? null;
            if ($id === null) {
                return $empty;
            }

            $out = $empty;
            // The form object carries declared fields only;
            // audit stamps and identity fallbacks live on
            // the raw row.
            $ownSlice = $manager->getSlice($sliceName);
            $table = $ownSlice?->primaryTable();
            $raw = null;
            if ($table !== null && Schema::hasTable($table)) {
                try {
                    $raw = DB::table($table)->where('id', $id)->first();
                } catch (\Throwable) {
                    $raw = null;
                }
            }
            $stamp = fn (string $key) => $raw?->{$key} ?? $record->{$key} ?? null;
            $out['identity'] = [
                'label' => DirectoryUi::recordLabel($record) ?? ('#'.$id),
                'name' => isset($record->name) && is_string($record->name) && $record->name !== '' ? $record->name : (isset($raw->name) && is_string($raw->name) && $raw->name !== '' ? $raw->name : ('#'.$id)),
                'status' => $record->status ?? $record->state ?? $record->stage ?? $stamp('status'),
            ];
            $out['audit'] = [
                'created_at' => $stamp('created_at'),
                'updated_at' => $stamp('updated_at'),
                'created_by' => self::userLabel($stamp('created_by')),
                'updated_by' => self::userLabel($stamp('updated_by')),
            ];
            $out['users'] = self::userOptions();
            $out['assignment'] = self::assignment($sliceName, $manifest, $record, $out['users']);

            // Prev / next by id within the slice's own table.
            if ($table !== null && Schema::hasTable($table)) {
                $out['prev'] = DB::table($table)->where('id', '<', $id)->max('id');
                $out['next'] = DB::table($table)->where('id', '>', $id)->min('id');
            }

            $out['connections'] = self::connections($manager, $sliceName, $id);
            $out['activity'] = self::activity($ownSlice, $id);
            $out['notes'] = self::extras('laraslice_record_notes', $sliceName, $id, ['id', 'body', 'user_id', 'created_at']);
            $out['attachments'] = self::extras('laraslice_record_attachments', $sliceName, $id, ['id', 'name', 'path', 'disk', 'size', 'mime', 'user_id', 'created_at']);
            $out['tags'] = self::extras('laraslice_record_tags', $sliceName, $id, ['id', 'tag', 'user_id', 'created_at']);
            $out['shares'] = self::extras('laraslice_record_shares', $sliceName, $id, ['id', 'user_id', 'shared_by', 'created_at']);
            foreach ($out['shares'] as &$share) {
                $share['user_label'] = $out['users'][$share['user_id']] ?? ('User #'.$share['user_id']);
            }
            foreach ($out['notes'] as &$note) {
                $note['user_label'] = $out['users'][$note['user_id']] ?? null;
            }

            return $out;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function extras(string $table, string $sliceName, mixed $id, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        try {
            return DB::table($table)
                ->where('slice', $sliceName)->where('record_id', (string) $id)
                ->orderByDesc('id')->limit(50)
                ->get($columns)->map(fn ($row) => (array) $row)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function activity(?SliceManifest $ownSlice, mixed $id): array
    {
        if ($ownSlice === null || ! Schema::hasTable('laraslice_audit_logs')) {
            return [];
        }
        $modelClass = $ownSlice->getModelClass();
        if ($modelClass === null) {
            return [];
        }
        try {
            return DB::table('laraslice_audit_logs')
                ->where('entity_type', $modelClass)->where('entity_id', (string) $id)
                ->orderByDesc('id')->limit(20)
                ->get(['id', 'action', 'actor_email', 'new_values', 'created_at'])
                ->map(function ($row) {
                    $keys = [];
                    $values = json_decode((string) ($row->new_values ?? ''), true);
                    if (is_array($values)) {
                        $keys = array_slice(array_keys(array_filter($values, fn ($v) => $v !== null && $v !== '')), 0, 6);
                    }

                    return [
                        'action' => (string) $row->action,
                        'actor' => $row->actor_email ?: 'System',
                        'at' => $row->created_at,
                        'summary' => $keys === [] ? '' : implode(', ', $keys),
                    ];
                })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array{slice: string, title: string, count: int, records: array<int, array{id: mixed, label: string, url: ?string}>}> */
    private static function connections(SliceManager $manager, string $sliceName, mixed $id): array
    {
        $manifests = [];
        $sliceObjects = [];
        foreach ($manager->getAllSlices() as $slice) {
            if ($slice instanceof SliceManifest) {
                $manifests[$slice->name] = $slice->raw;
                $sliceObjects[$slice->name] = $slice;
            }
        }
        $groups = [];
        foreach (self::inboundFields($manifests, $sliceName) as $link) {
            $other = $sliceObjects[$link['slice']] ?? null;
            $table = $other?->primaryTable();
            if ($table === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $link['field'])) {
                continue;
            }
            try {
                $query = DB::table($table)->where($link['field'], $id);
                if ($link['via'] !== null && Schema::hasColumn($table, $link['via'])) {
                    $query->where($link['via'], $sliceName);
                }
                $count = (clone $query)->count();
                if ($count === 0) {
                    continue;
                }
                $prefix = RecordConverter::targetRoutePrefix($link['slice']);
                $records = [];
                foreach ($query->orderByDesc('id')->limit(5)->get() as $row) {
                    $records[] = [
                        'id' => $row->id,
                        'label' => DirectoryUi::recordLabel($row) ?? ('#'.$row->id),
                        'url' => $prefix !== null && Route::has($prefix.'edit') ? route($prefix.'edit', $row->id) : null,
                    ];
                }
                $key = $link['slice'];
                if (! isset($groups[$key])) {
                    $groups[$key] = ['slice' => $link['slice'], 'title' => $other->title ?: $link['slice'], 'count' => 0, 'records' => []];
                }
                $groups[$key]['count'] += $count;
                $groups[$key]['records'] = array_merge($groups[$key]['records'], $records);
            } catch (\Throwable) {
                continue;
            }
        }

        // Dynamic-Link repeaters (D-077): child tables whose rows
        // carry a doctype + name pair (the source's Dynamic Link
        // shape — a Contact's links, an Address's links) point at
        // this record from a CHILD row, so the PARENT records are
        // the connection. The source's Connections panel includes
        // them; the scalar-field scan above cannot see them.
        foreach (self::repeaterLinkSources($manifests, $sliceName) as $link) {
            $other = $sliceObjects[$link['slice']] ?? null;
            $parentTable = $other?->primaryTable();
            if ($parentTable === null || ! Schema::hasTable($link['table']) || ! Schema::hasTable($parentTable)) {
                continue;
            }
            try {
                $parentIds = DB::table($link['table'])
                    ->where($link['doctype_field'], $sliceName)
                    ->where($link['name_field'], (string) $id)
                    ->pluck($link['foreign_key']);
                if ($parentIds->isEmpty()) {
                    continue;
                }
                $prefix = RecordConverter::targetRoutePrefix($link['slice']);
                $records = [];
                foreach (DB::table($parentTable)->whereIn('id', $parentIds->all())->orderByDesc('id')->limit(5)->get() as $row) {
                    $records[] = [
                        'id' => $row->id,
                        'label' => DirectoryUi::recordLabel($row) ?? ('#'.$row->id),
                        'url' => $prefix !== null && Route::has($prefix.'edit') ? route($prefix.'edit', $row->id) : null,
                    ];
                }
                $key = $link['slice'];
                if (! isset($groups[$key])) {
                    $groups[$key] = ['slice' => $link['slice'], 'title' => $other->title ?: $link['slice'], 'count' => 0, 'records' => []];
                }
                $groups[$key]['count'] += $parentIds->count();
                $groups[$key]['records'] = array_merge($groups[$key]['records'], $records);
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values($groups);
    }

    /** @return array<int, array{slice: string, table: string, foreign_key: string, doctype_field: string, name_field: string}> */
    private static function repeaterLinkSources(array $manifestsBySlice, string $sliceName): array
    {
        $out = [];
        foreach ($manifestsBySlice as $otherSlice => $manifest) {
            if ($otherSlice === $sliceName || ! is_array($manifest)) {
                continue;
            }
            foreach (SliceRecordOptions::manifestFieldsOf($manifest) as $definition) {
                if (! is_array($definition) || ($definition['type'] ?? null) !== 'repeater'
                    || empty($definition['table']) || empty($definition['foreign_key'])) {
                    continue;
                }
                $doctypeField = null;
                $nameField = null;
                foreach ((array) ($definition['repeater_fields'] ?? []) as $rf) {
                    if (! is_array($rf)) {
                        continue;
                    }
                    $handle = (string) ($rf['handle'] ?? $rf['name'] ?? '');
                    if ($handle === '') {
                        continue;
                    }
                    if (str_contains($handle, 'doctype')) {
                        $doctypeField = $handle;
                    } elseif ($handle === 'link_name' || str_ends_with($handle, '_name')) {
                        $nameField ??= $handle;
                    }
                }
                if ($doctypeField !== null && $nameField !== null) {
                    $out[] = [
                        'slice' => (string) $otherSlice,
                        'table' => (string) $definition['table'],
                        'foreign_key' => (string) $definition['foreign_key'],
                        'doctype_field' => $doctypeField,
                        'name_field' => $nameField,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The slice's assignment seat field: the declared
     * ui.workplace.assignment_field when it names a real
     * field (slices whose responsible-user column is not
     * owner-shaped — e.g. Customer's account_manager),
     * otherwise the first owner-shaped field, else null.
     * One resolver for the rail and the assign endpoint,
     * so a write can never land on an arbitrary column.
     */
    public static function assignmentField(array $manifest): ?string
    {
        $fields = SliceRecordOptions::manifestFieldsOf($manifest);
        $declared = $manifest['ui']['workplace']['assignment_field'] ?? null;
        if (is_string($declared) && $declared !== '' && array_key_exists($declared, $fields)) {
            return $declared;
        }
        foreach ($fields as $name => $definition) {
            if ($name === 'owner' || str_ends_with($name, '_owner')) {
                return $name;
            }
        }

        return null;
    }

    /** The sentinel form value for the generic assignment seat (no record column). */
    public const GENERIC_ASSIGNMENT_FIELD = '__workplace';

    /**
     * The record's assignment seat: its resolved assignment
     * field when it has one; otherwise the generic workplace
     * store, so a slice without an owner-shaped column still
     * gets an Assign To card (the source assigns any record,
     * whether or not its DocType carries an owner field).
     */
    private static function assignment(string $sliceName, array $manifest, object $record, array $users): ?array
    {
        $name = self::assignmentField($manifest);
        if ($name !== null) {
            $fields = SliceRecordOptions::manifestFieldsOf($manifest);
            $definition = $fields[$name] ?? [];
            $value = $record->{$name} ?? null;

            return [
                'field' => $name,
                'label' => (string) ($definition['label'] ?? Str::headline($name)),
                'user_id' => $value,
                'user_label' => $value !== null ? ($users[$value] ?? null) : null,
            ];
        }
        if (! Schema::hasTable('laraslice_record_assignments')) {
            return null;
        }
        $row = DB::table('laraslice_record_assignments')
            ->where('slice', $sliceName)->where('record_id', (string) ($record->id ?? ''))->first();
        $value = $row?->user_id;

        return [
            'field' => self::GENERIC_ASSIGNMENT_FIELD,
            'label' => 'Assign To',
            'user_id' => $value,
            'user_label' => $value !== null ? ($users[$value] ?? null) : null,
        ];
    }

    /** @return array<int|string, string> user id => display label */
    private static function userOptions(): array
    {
        if (! Schema::hasTable('users')) {
            return [];
        }
        try {
            $columns = Schema::getColumnListing('users');
            $label = in_array('name', $columns, true) ? 'name' : 'email';
            $out = [];
            foreach (DB::table('users')->orderBy($label)->limit(200)->get(['id', $label]) as $user) {
                $out[$user->id] = (string) $user->{$label};
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve a user-stamp value (a *_by column) to the
     * user's display name (name, else email), or null when
     * the value is empty or no such user exists — callers
     * then fall back to the raw value. Public because
     * generated edit forms render audit stamps (D-013)
     * through this same resolver as the workplace rail.
     */
    public static function userLabel(mixed $userId): ?string
    {
        if ($userId === null || ! Schema::hasTable('users')) {
            return null;
        }
        try {
            $user = DB::table('users')->where('id', $userId)->first(['name', 'email']);

            return $user === null ? null : (string) ($user->name ?? $user->email);
        } catch (\Throwable) {
            return null;
        }
    }
}
