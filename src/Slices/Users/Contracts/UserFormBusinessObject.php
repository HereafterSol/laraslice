<?php

namespace LaraSlice\Slices\Users\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class UserFormBusinessObject extends BaseFormBusinessObject
{
    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public string $status = 'active'; // active, suspended, pending

    public ?string $avatarUrl = null;

    public ?string $cnic = null;

    public ?string $gender = null; // male, female, other

    public ?string $phone = null;

    public ?string $dob = null;

    public ?string $employeeId = null;

    public ?string $department = null;

    public ?string $designation = null;

    public bool $customisedPermissions = false;

    public string $mfaChannel = 'none'; // none, totp, webauthn

    public array $roles = [];

    public array $roleIds = [];

    public array $permissions = [];
}
