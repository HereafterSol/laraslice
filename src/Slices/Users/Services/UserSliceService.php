<?php

namespace LaraSlice\Slices\Users\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Security\Access;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFilterObject;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Contracts\UserFormBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserListingBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserFilterBusinessObject;

class UserSliceService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return User::class;
    }

    protected function newQuery(): Builder
    {
        // Eager load detail and roles to avoid N+1 queries
        return parent::newQuery()->with(['detail', 'roles']);
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        /** @var User $model */
        $form = new UserFormBusinessObject();
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
                $roleIds = $model->roles ? $model->roles->pluck('id')->map(fn($id) => (int) $id)->toArray() : [];
            }
        } catch (\Throwable $e) {}

        if (empty($roleIds) && isset($model->id)) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                    $roleIds = \Illuminate\Support\Facades\DB::table('role_user')
                        ->where('user_id', $model->id)
                        ->pluck('role_id')
                        ->map(fn($id) => (int) $id)
                        ->toArray();
                }
            } catch (\Throwable $e) {}
        }

        $form->roles = $roleIds;
        $form->roleIds = $roleIds;

        try {
            if (method_exists($model, 'getAllPermissions')) {
                $form->permissions = $model->getAllPermissions()->map(fn($p) => [
                    'name' => $p->name ?? $p->slug ?? 'Permission',
                    'slug' => $p->slug ?? $p->name ?? '',
                ])->toArray();
            }
        } catch (\Throwable $e) {}

        return $form;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        /** @var User $model */
        $listing = new UserListingBusinessObject();
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

        $roleNames = [];
        try {
            if (method_exists($model, 'roles')) {
                $roleNames = $model->roles ? $model->roles->pluck('name')->toArray() : [];
            }
        } catch (\Throwable $e) {}

        if (empty($roleNames) && isset($model->id)) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('role_user') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                    $roleNames = \Illuminate\Support\Facades\DB::table('role_user')
                        ->join('roles', 'role_user.role_id', '=', 'roles.id')
                        ->where('role_user.user_id', $model->id)
                        ->pluck('roles.name')
                        ->toArray();
                }
            } catch (\Throwable $e) {}
        }

        $listing->roles = $roleNames;

        try {
            if (method_exists($model, 'getAllPermissions')) {
                $perms = $model->getAllPermissions();
                $listing->permissionsCount = $perms->count();
                $listing->permissions = $perms->pluck('name')->toArray();
            }
        } catch (\Throwable $e) {}

        return $listing;
    }

    protected function beforeSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        /** @var UserFormBusinessObject $form */
        /** @var User $model */
        $model->name = $form->name;
        $model->email = $form->email;
        $model->status = $form->status ?? 'active';
        $model->avatar_url = $form->avatarUrl;
        $model->gender = $form->gender;
        $model->phone = $form->phone;
        $model->customised_permissions = $form->customisedPermissions;
        if (!empty($form->mfaChannel)) {
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

        $requested = array_map('intval', (array) (! empty($form->roles) ? $form->roles : $form->roleIds));
        $current = $target ? $target->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all() : [];
        $actorRoles = method_exists($actor, 'roles')
            ? $actor->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all()
            : [];

        $added = array_diff($requested, $current);
        if (array_diff($added, $actorRoles) !== []) {
            throw ValidationException::withMessages(['roles' => 'You can only assign roles that you hold yourself.']);
        }
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

        // 1. Save or Update 1-to-1 UserDetail
        $model->detail()->updateOrCreate(
            ['user_id' => $model->id],
            [
                'employee_id' => $form->employeeId,
                'department'  => $form->department,
                'designation' => $form->designation,
                'cnic'        => $form->cnic,
                'dob'         => $form->dob,
            ]
        );

        // 2. Sync Roles
        $roles = !empty($form->roles) ? $form->roles : ($form->roleIds ?? null);
        if ($roles !== null) {
            $roleIds = array_map('intval', (array) $roles);
            if (method_exists($model, 'roles')) {
                $model->roles()->sync($roleIds);
            } elseif (isset($model->id) && \Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                \Illuminate\Support\Facades\DB::table('role_user')->where('user_id', $model->id)->delete();
                $rows = [];
                foreach ($roleIds as $rId) {
                    if ($rId > 0) {
                        $rows[] = ['role_id' => $rId, 'user_id' => $model->id];
                    }
                }
                if (!empty($rows)) {
                    \Illuminate\Support\Facades\DB::table('role_user')->insert($rows);
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
                $query->whereHas('roles', fn($q) => $q->where('slug', $filter->role));
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
