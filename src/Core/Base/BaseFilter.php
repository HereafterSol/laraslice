<?php

namespace LaraSlice\Core\Base;

use LaraSlice\Core\Contracts\IFilterObject;

class BaseFilter implements IFilterObject
{
    public ?string $search = null;

    public int $page = 1;

    public int $limit = 20;

    public ?string $sortBy = 'created_at';

    public bool $sortDesc = true;

    public function __construct(array $params = [])
    {
        $search = $params['search'] ?? null;
        $this->search = is_scalar($search) ? mb_substr(trim((string) $search), 0, 200) : null;

        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 20;
        $this->page = is_numeric($page) ? max(1, min(1000000, (int) $page)) : 1;
        $this->limit = is_numeric($limit) ? min(100, max(1, (int) $limit)) : 20;

        $sortBy = $params['sortBy'] ?? $params['sort_by'] ?? 'created_at';
        $this->sortBy = is_string($sortBy) && in_array($sortBy, $this->sortableColumns(), true)
            ? $sortBy
            : 'created_at';
        $this->sortDesc = isset($params['sortDesc']) ? filter_var($params['sortDesc'], FILTER_VALIDATE_BOOLEAN) : true;
    }

    /** @return list<string> */
    protected function sortableColumns(): array
    {
        return ['id', 'title', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Load the first $limit rows in one page. For server code only (e.g. data-table
     * index pages); request input stays capped at 100 by the constructor.
     */
    public function withLimit(int $limit): static
    {
        $this->page = 1;
        $this->limit = max(1, $limit);

        return $this;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getSortBy(): ?string
    {
        return $this->sortBy;
    }

    public function isSortDesc(): bool
    {
        return $this->sortDesc;
    }

    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'page' => $this->page,
            'limit' => $this->limit,
            'sortBy' => $this->sortBy,
            'sortDesc' => $this->sortDesc,
        ];
    }
}
