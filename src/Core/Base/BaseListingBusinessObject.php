<?php

namespace LaraSlice\Core\Base;

use LaraSlice\Core\Contracts\IBusinessObject;

abstract class BaseListingBusinessObject implements IBusinessObject
{
    public string|int $id;

    public ?string $createdAt = null;

    public string|int|null $createdBy = null;

    public ?string $creatorName = null;

    public ?string $updatedAt = null;

    public string|int|null $updatedBy = null;

    public ?string $updaterName = null;

    public ?string $deletedAt = null;

    public string|int|null $deletedBy = null;

    public ?string $deleterName = null;

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): static
    {
        $instance = new static;
        foreach ($data as $key => $value) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            } elseif (property_exists($instance, $camel)) {
                $instance->{$camel} = $value;
            }
        }

        return $instance;
    }
}
