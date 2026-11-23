<?php

namespace App\Http\Requests;

use App\Enums\CreditPack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buy a pack of Credits. Only the pack id is read: any amount the client
 * sends is ignored, and the pack sets the Top-up's amount.
 */
class BuyCreditsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'string', Rule::enum(CreditPack::class)],
        ];
    }

    public function pack(): CreditPack
    {
        return CreditPack::from($this->string('pack')->toString());
    }
}
