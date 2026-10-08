<?php

namespace LaraSlice\Slices\Auth\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class LoginFormBusinessObject extends BaseFormBusinessObject
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;
}
