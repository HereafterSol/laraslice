<?php

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\SliceRecordOptions;

/**
 * JSON options for dynamic form fields (Stage C-5): a
 * `slice_records:<via>` field refills its options from this
 * endpoint whenever the via field (the slice selector)
 * changes in the browser.
 */
class OptionsController extends Controller
{
    public function sliceRecords(Request $request): JsonResponse
    {
        $options = [];
        foreach (SliceRecordOptions::forSlice((string) $request->query('slice', '')) as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return response()->json(['options' => $options]);
    }

    /**
     * fetch_from read (Seat 1, Stage D): one field's
     * value from the record a link field points at. The
     * (via, field) pair must be DECLARED as a fetch_from
     * in the requesting slice's manifest — the endpoint
     * serves declared fetches only, never arbitrary
     * record reads.
     */
    public function fetch(Request $request): JsonResponse
    {
        $via = (string) $request->query('via', '');
        $field = (string) $request->query('field', '');
        $id = $request->query('id');
        if ($id === null || $id === '' || $via === '' || $field === '') {
            return response()->json(['value' => null]);
        }
        // With an explicit slice the check is strict.
        // Without one, the declaring slices are searched:
        // the fetch is served only when exactly ONE slice
        // declares this (via, field) pair — ambiguity
        // answers null rather than guessing.
        $sliceName = (string) $request->query('slice', '');
        $candidates = [];
        if ($sliceName !== '') {
            $slice = app(SliceManager::class)->getSlice($sliceName);
            if ($slice === null) {
                return response()->json(['value' => null], 404);
            }
            $candidates[] = $slice;
        } else {
            $candidates = array_values(app(SliceManager::class)->getActiveSlices());
        }
        $matches = [];
        foreach ($candidates as $slice) {
            $fields = SliceRecordOptions::manifestFieldsOf($slice->raw ?? []);
            foreach ($fields as $definition) {
                if (($definition['fetch_from'] ?? null) === $via.'.'.$field) {
                    $target = SliceRecordOptions::viaTargetSlice($fields, $via);
                    if ($target !== null) {
                        $matches[] = $target;
                    }
                    break;
                }
            }
        }
        if (count($matches) !== 1) {
            return response()->json(['value' => null]);
        }

        return response()->json(['value' => SliceRecordOptions::recordValue($matches[0], $id, $field)]);
    }
}
