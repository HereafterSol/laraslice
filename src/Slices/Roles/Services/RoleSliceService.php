<?php

namespace LaraSlice\Slices\Roles\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Roles\Contracts\RoleFormBusinessObject;
use LaraSlice\Slices\Roles\Contracts\RoleListingBusinessObject;

class RoleSliceService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return Role::class;
    }

    protected function newQuery(): Builder
    {
        // Listing rows show both counts; load them in the listing query
        return parent::newQuery()->withCount(['users', 'permissions'])->with('permissions');
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        /** @var Role $model */
        $form = new RoleFormBusinessObject();
        $form->id = $model->id;
        $form->name = $model->name;
        $form->slug = $model->slug;
        $form->description = $model->description;
        
        $permIds = $model->permissions ? $model->permissions->pluck('id')->toArray() : [];
        if ($model->slug === 'super-admin' && empty($permIds) && class_exists(\LaraSlice\Slices\Roles\Models\Permission::class)) {
            $permIds = \LaraSlice\Slices\Roles\Models\Permission::pluck('id')->toArray();
        }
        $form->permissions = $permIds;
        $form->permissionIds = $permIds;
        return $form;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        /** @var Role $model */
        $listing = new RoleListingBusinessObject();
        $listing->id = $model->id;
        $listing->name = $model->name;
        $listing->slug = $model->slug;
        $listing->description = $model->description;
        $listing->usersCount = (int) ($model->users_count ?? $model->users()->count());
        $listing->permissionsCount = (int) ($model->permissions_count ?? $model->permissions()->count());
        $listing->createdAt = $model->created_at ? $model->created_at->toIso8601String() : null;
        return $listing;
    }

    protected function beforeSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        /** @var RoleFormBusinessObject $form */
        /** @var Role $model */
        if (empty($form->slug)) {
            $model->slug = Str::slug($form->name);
        } else {
            $model->slug = Str::slug($form->slug);
        }
    }

    protected function afterSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        /** @var RoleFormBusinessObject $form */
        /** @var Role $model */
        $permList = !empty($form->permissions) ? $form->permissions : (!empty($form->permissionIds) ? $form->permissionIds : []);

        if ($model->slug === 'super-admin' && empty($permList) && class_exists(\LaraSlice\Slices\Roles\Models\Permission::class)) {
            $permList = \LaraSlice\Slices\Roles\Models\Permission::pluck('id')->toArray();
        }

        if (method_exists($model, 'permissions')) {
            $model->permissions()->sync($permList);
        }
    }

    protected function beforeDelete(Model $model): void
    {
        /** @var Role $model */
        if ($model->slug === 'super-admin') {
            throw new \DomainException("The Super Administrator role is system-protected and cannot be deleted.");
        }
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('slug', 'LIKE', "%{$search}%");
        });
    }
}
