<?php

namespace LaraSlice\Support;

/**
 * Name/type signal inference for directory columns — the single source
 * of truth shared by the porter (buildUiFromInspectedFields) and the
 * generator's default UI, so a hand-made slice renders with the same
 * formatters as a ported one (F5: generator inference parity).
 *
 * The rules were proven on real ports; two lessons are baked in:
 * money is a NAME-level signal, never a type-level one (decimal geo
 * coordinates once rendered as currency), and due dates get the
 * overdue treatment instead of a plain date.
 */
final class FieldInference
{
    /**
     * @return array{formatter: string, is_money: bool, is_status: bool, is_date: bool, is_due: bool, is_progress: bool, is_assignee: bool, is_priority: bool, is_code: bool}
     */
    public static function analyze(string $handle, string $type = 'string'): array
    {
        $lower = strtolower($handle);

        $isGeo = in_array($lower, ['lat', 'lng', 'latitude', 'longitude'], true)
            || str_ends_with($lower, '_latitude') || str_ends_with($lower, '_longitude')
            || str_ends_with($lower, '_lat') || str_ends_with($lower, '_lng');
        $isMoney = ! $isGeo && (str_contains($lower, 'amount') || str_contains($lower, 'total') || str_contains($lower, 'price') || str_contains($lower, 'balance') || str_contains($lower, 'grand_total') || str_contains($lower, 'cost') || str_contains($lower, 'salary') || str_ends_with($lower, '_fee'));
        $isStatus = $lower === 'status' || str_contains($lower, 'status_') || $lower === 'state' || $lower === 'stage';
        $isDate = str_ends_with($lower, '_at') || str_starts_with($lower, 'date') || in_array($type, ['datetime', 'date', 'timestamp'], true);
        $isDue = in_array($lower, ['due_date', 'deadline', 'due_on'], true) || str_ends_with($lower, '_due');
        $isProgress = str_contains($lower, 'progress') || str_contains($lower, 'percent') || str_contains($lower, 'completion') || $lower === 'completion_percent';
        $isAssignee = in_array($lower, ['user_id', 'assignee_id', 'assigned_to', 'employee_id', 'owner_id'], true)
            || str_ends_with($lower, '_by') && str_contains($lower, 'assign');
        $isPriority = $lower === 'priority' || $lower === 'urgency';
        $isCode = $lower === 'code' || str_ends_with($lower, '_code') || in_array($lower, ['invoice_number', 'order_number', 'task_short_code', 'project_short_code'], true);

        $formatter = 'text';
        if ($isProgress) {
            $formatter = 'progress_bar';
        } elseif ($isMoney) {
            $formatter = 'money_breakdown';
        } elseif ($isStatus) {
            $formatter = 'status_pill';
        } elseif ($isDue) {
            $formatter = 'date_overdue';
        } elseif ($isDate) {
            $formatter = 'date_time';
        } elseif ($isCode) {
            $formatter = 'code_badge';
        } elseif ($isAssignee) {
            $formatter = 'composite_user';
        } elseif ($isPriority) {
            $formatter = 'status_pill';
        }

        return [
            'formatter' => $formatter,
            'is_money' => $isMoney,
            'is_status' => $isStatus,
            'is_date' => $isDate,
            'is_due' => $isDue,
            'is_progress' => $isProgress,
            'is_assignee' => $isAssignee,
            'is_priority' => $isPriority,
            'is_code' => $isCode,
        ];
    }

    public static function formatterFor(string $handle, string $type = 'string'): string
    {
        return self::analyze($handle, $type)['formatter'];
    }
}
