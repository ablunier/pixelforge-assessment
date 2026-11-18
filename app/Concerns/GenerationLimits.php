<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Validation\Validator;

/**
 * For Form Requests that start a Generation: Pixelforge bills the business
 * for every one, so each user may have only so many in progress and so many
 * a day (ADR-0006). A user at a limit gets an error under `generate`.
 */
trait GenerationLimits
{
    /** Generations a user may have in progress (not final) at once. */
    public const int MAX_GENERATIONS_IN_PROGRESS = 5;

    /** Generations a user may start in any 24 hours. */
    public const int MAX_GENERATIONS_PER_DAY = 50;

    /**
     * @return list<Closure(Validator): void>
     */
    protected function generationLimits(User $user): array
    {
        return [function (Validator $validator) use ($user): void {
            if ($user->generations()->notFinal()->count() >= self::MAX_GENERATIONS_IN_PROGRESS) {
                $validator->errors()->add(
                    'generate',
                    'You have '.self::MAX_GENERATIONS_IN_PROGRESS.' Generations in progress. Wait for one to finish, then try again.',
                );
            } elseif ($user->generations()->where('created_at', '>', now()->subDay())->count() >= self::MAX_GENERATIONS_PER_DAY) {
                $validator->errors()->add(
                    'generate',
                    "You've started ".self::MAX_GENERATIONS_PER_DAY.' Generations in the last 24 hours, the most allowed. Try again later.',
                );
            }
        }];
    }
}
