<?php

namespace LaraSlice\Slices\Auth\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class RegisterFormBusinessObject extends BaseFormBusinessObject
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';
}
