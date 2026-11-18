<?php

namespace App\Policies;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /**
     * Only a Generation's owner can see it, or its image.
     */
    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }

    /**
     * Try again: only the owner of a failed Generation can.
     */
    public function tryAgain(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id
            && $generation->status === GenerationStatus::Failed;
    }
}
