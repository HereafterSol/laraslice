<?php

namespace LaraSlice\Slices\Workflows\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LaraSlice\Slices\Workflows\Models\WorkflowRoutingRule;
use LaraSlice\Slices\Workflows\Models\WorkflowTransition;

class RoutingResolver
{
    /**
     * Resolve the target assignee (user, role, department) for a transition.
     *
     * @return array{user_id: ?int, role: ?string, department: ?string}
     */
    public function resolve(WorkflowTransition $transition, $model, array $payload = [], $currentUser = null): array
    {
        // 1. Direct explicit payload override has highest priority
        if (isset($payload['assigned_to_user_id']) || isset($payload['assigned_to_role']) || isset($payload['assigned_to_department'])) {
            return [
                'user_id' => $payload['assigned_to_user_id'] ?? null,
                'role' => $payload['assigned_to_role'] ?? null,
                'department' => $payload['assigned_to_department'] ?? null,
            ];
        }

        // 2. Resolve via database routing rules
        $rule = $transition->routingRules()->first();
        if (! $rule) {
            return [
                'user_id' => null,
                'role' => null,
                'department' => null,
            ];
        }

        $currentUser = $currentUser ?: auth()->user();

        if ($rule->assign_current_user && $currentUser) {
            return [
                'user_id' => $currentUser->id,
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ];
        }

        return match ($rule->routing_type) {
            'user' => [
                'user_id' => $rule->target_user_id,
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ],
            'role' => [
                'user_id' => $this->resolveFirstUserWithRole($rule->target_role),
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ],
            'department' => [
                'user_id' => null,
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ],
            'hierarchy_manager' => [
                'user_id' => $this->resolveManager($currentUser),
                'role' => $rule->target_role ?? 'manager',
                'department' => $rule->target_department,
            ],
            'dynamic_field' => [
                'user_id' => $rule->user_field ? (int) data_get($model, $rule->user_field) : null,
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ],
            'round_robin' => $this->resolveRoundRobin($rule),
            default => [
                'user_id' => $rule->target_user_id,
                'role' => $rule->target_role,
                'department' => $rule->target_department,
            ],
        };
    }

    protected function resolveFirstUserWithRole(?string $role): ?int
    {
        if (! $role) {
            return null;
        }

        try {
            if (class_exists(User::class)) {
                // If using Spatie permissions or role column
                $query = User::query();
                if (method_exists(User::class, 'role')) {
                    $u = User::role($role)->first();
                    if ($u) {
                        return $u->id;
                    }
                }
                $user = $query->where('role', $role)->first();
                if ($user) {
                    return $user->id;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected function resolveManager($currentUser): ?int
    {
        if (! $currentUser) {
            return null;
        }

        // Check common manager attributes: manager_id, supervisor_id, reports_to_id
        foreach (['manager_id', 'supervisor_id', 'reports_to_id'] as $attr) {
            if (! empty($currentUser->{$attr})) {
                return (int) $currentUser->{$attr};
            }
        }

        // Check user_details table if present
        try {
            $detail = DB::table('user_details')->where('user_id', $currentUser->id)->first();
            if ($detail && ! empty($detail->manager_id)) {
                return (int) $detail->manager_id;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected function resolveRoundRobin(WorkflowRoutingRule $rule): array
    {
        try {
            $query = User::query();
            if ($rule->target_department) {
                $query->where('department', $rule->target_department);
            }
            if ($rule->target_role && method_exists(User::class, 'role')) {
                $query->role($rule->target_role);
            }

            $users = $query->pluck('id')->all();
            if (! empty($users)) {
                // Pick round robin based on count of assigned items or random
                $selected = $users[array_rand($users)];

                return [
                    'user_id' => $selected,
                    'role' => $rule->target_role,
                    'department' => $rule->target_department,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'user_id' => null,
            'role' => $rule->target_role,
            'department' => $rule->target_department,
        ];
    }
}