<?php

declare(strict_types=1);

namespace LaraSlice\Core\Workflow\Events;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Slices\Workflows\Models\WorkflowLog;

/**
 * Dispatched after every successful workflow transition —
 * the hook approval notifications (and any host integration)
 * subscribe to. Carries the entity, the transition, the log,
 * and who performed it.
 */
class WorkflowTransitioned
{
    public function __construct(
        public readonly Model $model,
        public readonly mixed $transition,
        public readonly WorkflowLog $log,
        public readonly ?int $performedById,
    ) {}
}
