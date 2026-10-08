<?php

namespace LaraSlice\Core\Contracts;

interface IFormDataService
{
    /**
     * Save (Create or Update) a form business object.
     *
     * @return string|int The ID of the saved record
     */
    public function save(IBusinessObject $form): string|int;

    /**
     * Retrieve a single form business object by its ID.
     */
    public function getItemById(string|int $id): ?IBusinessObject;

    /**
     * Delete an item by its ID.
     */
    public function delete(string|int $id): bool;
}
