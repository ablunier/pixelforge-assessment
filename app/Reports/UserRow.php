<?php

namespace App\Reports;

use App\Models\User;
use JsonSerializable;

/**
 * One user's figures over the admin report's date range.
 */
final readonly class UserRow implements JsonSerializable
{
    public function __construct(
        public User $user,
        public Figures $figures,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return [
            'userId' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            ...$this->figures->jsonSerialize(),
        ];
    }
}
