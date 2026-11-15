<?php

declare(strict_types=1);

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Seat 2 (record workplace) write endpoints: notes,
 * attachments, tags, shares and owner assignment for
 * ANY slice's records — one controller behind the
 * record page's rail and Notes tab, so the workplace
 * behaves identically on every slice. All mutations
 * land in the generic laraslice_record_* stores (or,
 * for assignment, the record's own owner column).
 */
class RecordExtrasController extends Controller
{
    /** Resolve slice + record, or abort. Returns [SliceManifest, table]. */
    private function target(string $slice, string $id): array
    {
        $manifest = app(SliceManager::class)->getSlice($slice);
        $table = $manifest?->primaryTable();
        if ($manifest === null || $table === null || ! Schema::hasTable($table)
            || ! DB::table($table)->where('id', $id)->exists()) {
            abort(404);
        }

        return [$manifest, $table];
    }

    public function noteStore(Request $request, string $slice, string $id): RedirectResponse
    {
        $this->target($slice, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        DB::table('laraslice_record_notes')->insert([
            'slice' => $slice, 'record_id' => $id,
            'user_id' => $request->user()?->id, 'body' => $data['body'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Note added.');
    }

    public function noteDestroy(Request $request, string $slice, string $id, string $note): RedirectResponse
    {
        $this->target($slice, $id);
        DB::table('laraslice_record_notes')
            ->where('slice', $slice)->where('record_id', $id)->where('id', $note)->delete();

        return back()->with('success', 'Note removed.');
    }

    public function attachmentStore(Request $request, string $slice, string $id): RedirectResponse
    {
        $this->target($slice, $id);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $data['file'];
        $path = $file->store("laraslice/attachments/{$slice}/{$id}", 'public');
        DB::table('laraslice_record_attachments')->insert([
            'slice' => $slice, 'record_id' => $id,
            'user_id' => $request->user()?->id, 'disk' => 'public',
            'path' => $path, 'name' => $file->getClientOriginalName(),
            'size' => $file->getSize() ?: 0, 'mime' => $file->getMimeType(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Attachment added.');
    }

    public function attachmentDestroy(Request $request, string $slice, string $id, string $attachment): RedirectResponse
    {
        $this->target($slice, $id);
        $row = DB::table('laraslice_record_attachments')
            ->where('slice', $slice)->where('record_id', $id)->where('id', $attachment)->first();
        if ($row !== null) {
            Storage::disk($row->disk ?: 'public')->delete($row->path);
            DB::table('laraslice_record_attachments')->where('id', $row->id)->delete();
        }

        return back()->with('success', 'Attachment removed.');
    }

    public function tagStore(Request $request, string $slice, string $id): RedirectResponse
    {
        $this->target($slice, $id);
        $data = $request->validate(['tag' => ['required', 'string', 'max:80']]);
        DB::table('laraslice_record_tags')->insertOrIgnore([
            'slice' => $slice, 'record_id' => $id, 'tag' => trim($data['tag']),
            'user_id' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Tag added.');
    }

    public function tagDestroy(Request $request, string $slice, string $id, string $tag): RedirectResponse
    {
        $this->target($slice, $id);
        DB::table('laraslice_record_tags')
            ->where('slice', $slice)->where('record_id', $id)->where('id', $tag)->delete();

        return back()->with('success', 'Tag removed.');
    }

    public function shareStore(Request $request, string $slice, string $id): RedirectResponse
    {
        $this->target($slice, $id);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        if (! Schema::hasTable('users') || ! DB::table('users')->where('id', $data['user_id'])->exists()) {
            return back()->with('error', 'That user does not exist.');
        }
        DB::table('laraslice_record_shares')->insertOrIgnore([
            'slice' => $slice, 'record_id' => $id, 'user_id' => $data['user_id'],
            'shared_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Record shared.');
    }

    public function shareDestroy(Request $request, string $slice, string $id, string $share): RedirectResponse
    {
        $this->target($slice, $id);
        DB::table('laraslice_record_shares')
            ->where('slice', $slice)->where('record_id', $id)->where('id', $share)->delete();

        return back()->with('success', 'Share removed.');
    }

    public function assign(Request $request, string $slice, string $id): RedirectResponse
    {
        [$manifest, $table] = $this->target($slice, $id);
        $data = $request->validate([
            'field' => ['required', 'string'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $field = $data['field'];
        // The generic seat: slices with no resolved
        // assignment field assign into the workplace store
        // instead of a record column. It exists only when
        // no field seat does — the two never compete.
        if ($field === \LaraSlice\Support\RecordWorkplace::GENERIC_ASSIGNMENT_FIELD) {
            if (\LaraSlice\Support\RecordWorkplace::assignmentField($manifest->raw) !== null
                || ! Schema::hasTable('laraslice_record_assignments')) {
                abort(422, 'That field is not assignable.');
            }
            $userId = $data['user_id'] ?? null;
            if ($userId === null) {
                DB::table('laraslice_record_assignments')
                    ->where('slice', $slice)->where('record_id', $id)->delete();
            } else {
                DB::table('laraslice_record_assignments')->updateOrInsert(
                    ['slice' => $slice, 'record_id' => $id],
                    ['user_id' => $userId, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            return back()->with('success', 'Assignment updated.');
        }
        // Only the slice's resolved assignment seat is
        // assignable — the declared workplace field or its
        // owner-shaped column; never an arbitrary write.
        // ($manifest->raw is the parsed Heart; toArray() is
        // a summary shape without ui/fields.)
        if ($field !== \LaraSlice\Support\RecordWorkplace::assignmentField($manifest->raw)
            || ! Schema::hasColumn($table, $field)) {
            abort(422, 'That field is not assignable.');
        }
        DB::table($table)->where('id', $id)->update([$field => $data['user_id'] ?? null]);

        return back()->with('success', 'Assignment updated.');
    }
}
