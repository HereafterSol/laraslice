<?php

namespace LaraSlice\Slices\Users\Controllers;

use LaraSlice\Core\Base\BaseSliceApiController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Slices\Users\Contracts\UserFilterBusinessObject;
use LaraSlice\Slices\Users\Contracts\UserFormBusinessObject;
use LaraSlice\Slices\Users\Services\UserSliceService;

class UserApiController extends BaseSliceApiController
{
    protected UserSliceService $service;

    public function __construct(UserSliceService $service)
    {
        $this->service = $service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return $this->service;
    }

    protected function getFormClass(): string
    {
        return UserFormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return UserFilterBusinessObject::class;
    }
}
