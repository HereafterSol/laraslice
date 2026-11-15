<?php

declare(strict_types=1);

namespace LaraSlice\Core\Base;

use RuntimeException;

/**
 * A delete was refused because other records link to this
 * one (Phase 7, D-081). The source refuses such deletes
 * outright ("cannot delete a document that is linked from
 * other documents"); deleting anyway orphans every inbound
 * reference. Carries the inbound link count so surfaces can
 * say how many records point at the one being deleted.
 */
class LinkedRecordException extends RuntimeException
{
    public function __construct(private readonly int $linkCount)
    {
        parent::__construct(
            "This record is linked from {$linkCount} other record(s) and cannot be deleted. Remove or reassign those links first."
        );
    }

    public function linkCount(): int
    {
        return $this->linkCount;
    }
}
