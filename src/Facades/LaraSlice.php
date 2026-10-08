<?php

namespace LaraSlice\Facades;

use Illuminate\Support\Facades\Facade;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * @method static array getAllSlices()
 * @method static array getActiveSlices()
 * @method static array getNavigableSlices()
 * @method static \LaraSlice\Core\Discovery\SliceManifest|null getSlice(string $name)
 * @method static int syncPermissions(bool $prune = false)
 * @method static int syncPermissionsIfChanged()
 *
 * @see \LaraSlice\Core\Discovery\SliceManager
 */
class LaraSlice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SliceManager::class;
    }
}
