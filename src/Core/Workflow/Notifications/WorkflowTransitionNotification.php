<?php

declare(strict_types=1);

namespace LaraSlice\Core\Workflow\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells a user that a record they own or are assigned to moved
 * in its workflow ("your leave was approved", "a ticket was
 * assigned to you"). Database channel by default; hosts may
 * extend via Notification::send conventions.
 */
class WorkflowTransitionNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
        ];
    }
}
