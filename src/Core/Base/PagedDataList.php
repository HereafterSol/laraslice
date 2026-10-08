<?php

namespace LaraSlice\Core\Base;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

class PagedDataList implements Arrayable, Jsonable
{
    public array $items;

    public int $totalCount;

    public int $pageIndex;

    public int $pageSize;

    public int $totalPages;

    public bool $hasPreviousPage;

    public bool $hasNextPage;

    public function __construct(array $items, int $totalCount, int $pageIndex = 1, int $pageSize = 20)
    {
        $this->items = $items;
        $this->totalCount = $totalCount;
        $this->pageIndex = max(1, $pageIndex);
        $this->pageSize = max(1, $pageSize);
        $this->totalPages = (int) ceil($this->totalCount / $this->pageSize);
        $this->hasPreviousPage = $this->pageIndex > 1;
        $this->hasNextPage = $this->pageIndex < $this->totalPages;
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(fn ($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item, $this->items),
            'totalCount' => $this->totalCount,
            'pageIndex' => $this->pageIndex,
            'pageSize' => $this->pageSize,
            'totalPages' => $this->totalPages,
            'hasPreviousPage' => $this->hasPreviousPage,
            'hasNextPage' => $this->hasNextPage,
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
