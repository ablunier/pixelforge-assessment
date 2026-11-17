<?php

namespace App\Enums;

/**
 * Where a Generation is in its lifecycle. `pending` is local (saved, not yet
 * accepted by Pixelforge, see ADR-0003); the others are Pixelforge's.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Status only moves to a higher rank. Both final statuses share the top rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether a Generation in this status may move to `$next`: forward only,
     * and never away from a final status.
     */
    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }
}
