<?php

namespace LaraSlice\Slices\Users\Contracts;

use LaraSlice\Core\Base\BaseListingBusinessObject;

class UserListingBusinessObject extends BaseListingBusinessObject
{
    public string $name = '';

    public string $email = '';

    public string $status = 'active';

    public ?string $avatarUrl = null;

    public ?string $cnic = null;

    public ?string $gender = null;

    public ?string $phone = null;

    public ?string $employeeId = null;

    public ?string $department = null;

    public ?string $designation = null;

    public string $mfaChannel = 'none';

    public array $roles = [];

    public int $permissionsCount = 0;

    public array $permissions = [];
}
