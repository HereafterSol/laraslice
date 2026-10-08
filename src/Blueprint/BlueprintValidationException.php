<?php

namespace LaraSlice\Blueprint;

use InvalidArgumentException;

final class BlueprintValidationException extends InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct("Blueprint is invalid:\n- ".implode("\n- ", $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
