<?php

namespace LaraSlice\Slices\Roles\Contracts;

use LaraSlice\Core\Base\BaseListingBusinessObject;

class RoleListingBusinessObject extends BaseListingBusinessObject
{
    public string $name = '';

    public string $slug = '';

    public ?string $description = null;

    public int $usersCount = 0;

    public int $permissionsCount = 0;
}
