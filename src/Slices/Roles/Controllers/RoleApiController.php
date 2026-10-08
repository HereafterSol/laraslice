<?php

namespace LaraSlice\Slices\Roles\Controllers;

use LaraSlice\Core\Base\BaseSliceApiController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Slices\Roles\Contracts\RoleFilterBusinessObject;
use LaraSlice\Slices\Roles\Contracts\RoleFormBusinessObject;
use LaraSlice\Slices\Roles\Services\RoleSliceService;

class RoleApiController extends BaseSliceApiController
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
}
