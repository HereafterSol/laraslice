<?php

namespace LaraSlice\Slices\Users\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFilterObject;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Users\Contracts\UserFilterBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserFormBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserListingBusinessObject;
use LaraSlice\Slices\Users\Models\User;

class UserSliceService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return User::class;
    }

    protected function newQuery(): Builder
    {
        // Eager load detail, roles and their permissions: listings show both per row
        return parent::newQuery()->with(['detail', 'roles.permissions']);
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        /** @var User $model */
        $form = new UserFormBusinessObject;
        $form->id = $model->id;
        $form->name = $model->name;
        $form->email = $model->email;
        $form->status = $model->status ?? 'active';
        $form->avatarUrl = $model->avatar_url;
        $form->gender = $model->gender;
        $form->phone = $model->phone;
        $form->customisedPermissions = (bool) ($model->customised_permissions ?? false);
        $form->mfaChannel = $model->mfa_channel ?? 'none';

        // 1-to-1 UserDetail fields
        $detail = $model->detail;
        $form->cnic = $detail?->cnic;
        $form->employeeId = $detail?->employee_id;
        $form->department = $detail?->department;
        $form->designation = $detail?->designation;
        $form->dob = $detail?->dob ? (is_string($detail->dob) ? $detail->dob : $detail->dob->format('Y-m-d')) : null;

        $roleIds = [];
        try {
            if (method_exists($model, 'roles')) {
                $roleIds = $model->roles ? $model->roles->pluck('id')->map(fn ($id) => (int) $id)->toArray() : [];
            }
        } catch (\Throwable $e) {
        }

        if (empty($roleIds) && isset($model->id)) {
            try {
                if (Schema::hasTable('role_user')) {
                    $roleIds = DB::table('role_user')
                        ->where('user_id', $model->id)
                        ->pluck('role_id')
                        ->map(fn ($id) => (int) $id)
                        ->toArray();
                }
            } catch (\Throwable $e) {
            }
        }

        $form->roles = $roleIds;
        $form->roleIds = $roleIds;

        try {
            if (method_exists($model, 'getAllPermissions')) {
                $form->permissions = $model->getAllPermissions()->map(fn ($p) => [
                    'name' => $p->name ?? $p->slug ?? 'Permission',
                    'slug' => $p->slug ?? $p->name ?? '',
                ])->toArray();
            }
        } catch (\Throwable $e) {
        }

        return $form;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        /** @var User $model */
        $listing = new UserListingBusinessObject;
        $listing->id = $model->id;
        $listing->name = $model->name;
        $listing->email = $model->email;
        $listing->status = $model->status ?? 'active';
        $listing->avatarUrl = $model->avatar_url;
        $listing->gender = $model->gender;
        $listing->phone = $model->phone;
        $listing->mfaChannel = $model->mfa_channel ?? 'none';
        $listing->createdAt = $model->created_at ? $model->created_at->toIso8601String() : null;

        // 1-to-1 UserDetail fields
        $detail = $model->detail;
        $listing->cnic = $detail?->cnic;
        $listing->employeeId = $detail?->employee_id;
        $listing->department = $detail?->department;
        $listing->designation = $detail?->designation;

        // roles is eager-loaded by newQuery(); an empty collection means the user has no roles
        $listing->roles = $model->roles->pluck('name')->all();

        try {
            if (method_exists($model, 'getAllPermissions')) {
                $perms = $model->getAllPermissions();
                $listing->permissionsCount = $perms->count();
                $listing->permissions = $perms->pluck('name')->toArray();
            }
        } catch (\Throwable $e) {
        }

        return $listing;
    }

    protected function beforeSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        /** @var UserFormBusinessObject $form */
        /** @var User $model */
        // On update, only change what the request supplied
        $assign = function (string $property, string $column, mixed $value) use ($form, $model, $isNew): void {
            if ($isNew || $form->provided($property)) {
                $model->{$column} = $value;
            }
        };

        $assign('name', 'name', $form->name);
        $assign('email', 'email', $form->email);
        $assign('status', 'status', $form->status ?: 'active');
        $assign('avatarUrl', 'avatar_url', $form->avatarUrl);
        $assign('gender', 'gender', $form->gender);
        $assign('phone', 'phone', $form->phone);
        $assign('customisedPermissions', 'customised_permissions', $form->customisedPermissions);
        if (! empty($form->mfaChannel) && ($isNew || $form->provided('mfaChannel'))) {
            $model->mfa_channel = $form->mfaChannel;
        }
    }

    protected function validate(IBusinessObject $form): void
    {
        /** @var UserFormBusinessObject $form */
        if (empty($form->id) && empty($form->password)) {
            throw ValidationException::withMessages(['password' => 'A password is required for new users.']);
        }

        if (! empty($form->password) && mb_strlen($form->password) < 8) {
            throw ValidationException::withMessages(['password' => 'The password must be at least 8 characters.']);
        }

        $this->guardRoleAssignment($form);
    }

    /**
     * Non-super-admins may not edit super-admin accounts, and may only grant roles they hold themselves.
     */
    protected function guardRoleAssignment(UserFormBusinessObject $form): void
    {
        $actor = auth()->user();

        // Console commands and seeders run without an actor
        if (! $actor || Access::isSuperAdmin($actor)) {
            return;
        }

        $target = empty($form->id) ? null : User::find($form->id);

        if ($target && Access::isSuperAdmin($target)) {
            throw new AuthorizationException('Only a super-admin can modify a super-admin account.');
        }

        $requested = $this->requestedRoleIds($form);
        if ($requested === null) {
            return; // roles are not being changed
        }
        $current = $target ? $target->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all() : [];
        $actorRoles = method_exists($actor, 'roles')
            ? $actor->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all()
            : [];

        $added = array_diff($requested, $current);
        if (array_diff($added, $actorRoles) !== []) {
            throw ValidationException::withMessages(['roles' => 'You can only assign roles that you hold yourself.']);
        }
    }

    /**
     * Role ids the request wants the user to hold, or null when roles are not being changed.
     *
     * @return array<int, int>|null
     */
    protected function requestedRoleIds(UserFormBusinessObject $form): ?array
    {
        $provided = $form->providedFields();

        if ($provided === null) {
            // Built in code rather than from a request: an empty list means "not specified"
            $roles = ! empty($form->roles) ? $form->roles : (! empty($form->roleIds) ? $form->roleIds : null);
        } elseif (in_array('roles', $provided, true)) {
            $roles = $form->roles;
        } elseif (in_array('roleIds', $provided, true)) {
            $roles = $form->roleIds;
        } else {
            $roles = null;
        }

        return $roles === null ? null : array_values(array_filter(array_map('intval', (array) $roles)));
    }

    protected function prepareModelForSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        parent::prepareModelForSave($form, $model, $isNew);

        /** @var UserFormBusinessObject $form */
        // fill() copies a null password from the form; keep the stored hash unless a new one was given
        if (empty($form->password) && ! $isNew) {
            $model->setRawAttributes(array_merge($model->getAttributes(), [
                'password' => $model->getRawOriginal('password'),
            ]));
        }
    }

    protected function afterSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        /** @var UserFormBusinessObject $form */
        /** @var User $model */

        // 1. Save or Update 1-to-1 UserDetail (only the supplied fields on update)
        $detail = array_filter([
            'employee_id' => ['employeeId', $form->employeeId],
            'department' => ['department', $form->department],
            'designation' => ['designation', $form->designation],
            'cnic' => ['cnic', $form->cnic],
            'dob' => ['dob', $form->dob],
        ], fn (array $pair) => $isNew || $form->provided($pair[0]));
        if ($detail !== []) {
            $model->detail()->updateOrCreate(['user_id' => $model->id], array_map(fn (array $pair) => $pair[1], $detail));
        }

        // 2. Sync Roles only when the request supplied them ("roles" or "roleIds")
        $roles = $this->requestedRoleIds($form);
        if ($roles !== null) {
            $roleIds = array_map('intval', (array) $roles);
            if (method_exists($model, 'roles')) {
                $model->roles()->sync($roleIds);
                $model->flushSlicePermissionCache();
            } elseif (isset($model->id) && Schema::hasTable('role_user')) {
                DB::table('role_user')->where('user_id', $model->id)->delete();
                $rows = [];
                foreach ($roleIds as $rId) {
                    if ($rId > 0) {
                        $rows[] = ['role_id' => $rId, 'user_id' => $model->id];
                    }
                }
                if (! empty($rows)) {
                    DB::table('role_user')->insert($rows);
                }
            }
        }
    }

    protected function applyFilters(Builder $query, IFilterObject $filter): void
    {
        parent::applyFilters($query, $filter);

        if ($filter instanceof UserFilterBusinessObject) {
            if ($filter->status) {
                $query->where('status', $filter->status);
            }
            if ($filter->role) {
                $query->whereHas('roles', fn ($q) => $q->where('slug', $filter->role));
            }
        }
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhere('phone', 'LIKE', "%{$search}%")
                ->orWhereHas('detail', function ($sub) use ($search) {
                    $sub->where('cnic', 'LIKE', "%{$search}%")
                        ->orWhere('employee_id', 'LIKE', "%{$search}%")
                        ->orWhere('department', 'LIKE', "%{$search}%")
                        ->orWhere('designation', 'LIKE', "%{$search}%");
                });
        });
    }
}
