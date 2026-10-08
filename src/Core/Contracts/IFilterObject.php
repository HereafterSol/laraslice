<?php

namespace LaraSlice\Core\Contracts;

interface IFilterObject
{
    public function getSearch(): ?string;

    public function getPage(): int;

    public function getLimit(): int;

    public function getSortBy(): ?string;

    public function isSortDesc(): bool;

    public function toArray(): array;
}
