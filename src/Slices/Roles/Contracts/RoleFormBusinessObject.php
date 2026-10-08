<?php

namespace LaraSlice\Slices\Roles\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class RoleFormBusinessObject extends BaseFormBusinessObject
{
    public string $name = '';

    public string $slug = '';

    public ?string $description = null;

    public array $permissions = [];

    public array $permissionIds = [];
}
