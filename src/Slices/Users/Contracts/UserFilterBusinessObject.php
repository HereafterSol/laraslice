<?php

namespace LaraSlice\Slices\Users\Contracts;

use LaraSlice\Core\Base\BaseFilter;

class UserFilterBusinessObject extends BaseFilter
{
    public ?string $status = null;

    public ?string $role = null;

    public function __construct(array $params = [])
    {
        parent::__construct($params);
        $this->status = $params['status'] ?? null;
        $this->role = $params['role'] ?? null;
    }
}
