<?php

namespace App\Http\Requests;

use App\Concerns\GenerationLimits;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Try again on a failed Generation. Who may is the route's `tryAgain` policy
 * check; this applies the same limits as Generate.
 */
class TryAgainRequest extends FormRequest
{
    use GenerationLimits;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        /** @var User $user */
        $user = $this->user();

        return $this->generationLimits($user);
    }
}
