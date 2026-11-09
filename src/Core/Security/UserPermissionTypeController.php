<?php

declare(strict_types=1);

namespace LaraSlice\Core\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Write endpoints for the employee hub's Permissions tab
 * (per-user permission types). Only a user who may edit
 * employee records — or a super persona — may customise
 * another person's types; the tab renders read-only for
 * everyone else and these endpoints refuse them regardless.
 * A target holding the 'admin' role is refused, mirroring
 * the source (its tab shows the admin notice instead of the
 * matrix, and admin access is not editable there).
 */
final class UserPermissionTypeController extends Controller
{
    public function update(Request $request, int $userId): JsonResponse
    {
        if (! $this->canManage($request)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }
        if ($this->targetHasAdminRole($userId)) {
            return response()->json(['status' => 'error', 'message' => 'Admin permissions are not editable.'], 422);
        }

        $permissionId = (int) $request->input('permission_id', 0);
        $typeId = (int) $request->input('permission_type_id', 0);
        if ($permissionId <= 0 || $typeId <= 0 || ! UserPermissionTypes::setType($userId, $permissionId, $typeId)) {
            return response()->json(['status' => 'error', 'message' => 'That permission type is not allowed for this permission.'], 422);
        }

        return response()->json(['status' => 'success', 'customised' => true]);
    }

    public function reset(Request $request, int $userId): JsonResponse
    {
        if (! $this->canManage($request)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        UserPermissionTypes::reset($userId);

        return response()->json(['status' => 'success']);
    }

    private function canManage(Request $request): bool
    {
        $viewer = $request->user();
        if (! is_object($viewer)) {
            return false;
        }

        return Access::isSuperAdmin($viewer)
            || Access::isSuperDev($viewer)
            || Access::allows($viewer, 'employee_detail.edit');
    }

    private function targetHasAdminRole(int $userId): bool
    {
        $matrix = UserPermissionTypes::matrixForUser($userId);

        return $matrix !== null && $matrix['admin_role'] === true;
    }
}
