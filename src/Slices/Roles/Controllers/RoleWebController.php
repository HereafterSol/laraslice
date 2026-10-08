<?php

namespace LaraSlice\Slices\Roles\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Roles\Contracts\RoleFilterBusinessObject;
use LaraSlice\Slices\Roles\Contracts\RoleFormBusinessObject;
use LaraSlice\Slices\Roles\Models\Permission;
use LaraSlice\Slices\Roles\Services\RoleSliceService;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;

class RoleWebController extends BaseSliceWebController
{
    protected RoleSliceService $service;

    public function __construct(RoleSliceService $service)
    {
        $this->service = $service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return $this->service;
    }

    protected function getFormClass(): string
    {
        return RoleFormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return RoleFilterBusinessObject::class;
    }

    protected function getViewPrefix(): string
    {
        return 'roles::';
    }

    protected function getRoutePrefix(): string
    {
        return 'roles.';
    }

    public function create()
    {
        $this->authorizeSlice('create');

        $formClass = $this->getFormClass();
        $form = new $formClass;
        app(SliceManager::class)->syncPermissionsIfChanged();
        $permissions = Permission::all()->groupBy('group');

        return view($this->getViewPrefix().'form', [
            'form' => $form,
            'isNew' => true,
            'routePrefix' => $this->getRoutePrefix(),
            'groupedPermissions' => $permissions,
        ]);
    }

    public function edit(string|int $id)
    {
        $this->authorizeSlice('edit');

        $form = $this->getService()->getItemById($id);

        if (! $form) {
            return redirect()->route($this->getRoutePrefix().'index')->with('error', 'Role not found');
        }

        app(SliceManager::class)->syncPermissionsIfChanged();
        $permissions = Permission::all()->groupBy('group');

        $isMfaEnforced = false;
        if (class_exists(SecurityPolicyService::class) && ! empty($form->slug)) {
            $isMfaEnforced = in_array($form->slug, SecurityPolicyService::getPrivilegedRoles());
        }

        return view($this->getViewPrefix().'form', [
            'form' => $form,
            'isNew' => false,
            'routePrefix' => $this->getRoutePrefix(),
            'groupedPermissions' => $permissions,
            'isMfaEnforced' => $isMfaEnforced,
        ]);
    }

    public function store(Request $request)
    {
        $response = parent::store($request);

        if (class_exists(SecurityPolicyService::class)) {
            $slug = $request->input('slug') ?: Str::slug($request->input('name'));
            if ($slug && $request->boolean('enforce_mfa')) {
                $privileged = SecurityPolicyService::getPrivilegedRoles();
                if (! in_array($slug, $privileged)) {
                    $privileged[] = $slug;
                    SecurityPolicyService::set(
                        'security.mfa_privileged_roles',
                        json_encode(array_values(array_unique($privileged))),
                        'Roles requiring mandatory MFA under Privileged Roles Only policy'
                    );
                }
            }
        }

        return $response;
    }

    public function update(Request $request, string|int $id)
    {
        $response = parent::update($request, $id);

        if (class_exists(SecurityPolicyService::class)) {
            $form = $this->getService()->getItemById($id);
            $slug = $request->input('slug') ?: ($form ? $form->slug : null);
            if ($slug) {
                $privileged = SecurityPolicyService::getPrivilegedRoles();
                if ($request->boolean('enforce_mfa')) {
                    if (! in_array($slug, $privileged)) {
                        $privileged[] = $slug;
                    }
                } else {
                    $privileged = array_values(array_diff($privileged, [$slug]));
                }
                SecurityPolicyService::set(
                    'security.mfa_privileged_roles',
                    json_encode(array_values(array_unique($privileged))),
                    'Roles requiring mandatory MFA under Privileged Roles Only policy'
                );
            }
        }

        return $response;
    }
}
