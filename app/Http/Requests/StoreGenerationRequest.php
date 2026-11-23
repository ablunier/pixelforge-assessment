<?php

namespace App\Http\Requests;

use App\Concerns\GenerationLimits;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGenerationRequest extends FormRequest
{
    use GenerationLimits;

    /** Pixelforge's prompt limit, in Unicode characters. */
    public const int MAX_PROMPT_LENGTH = 4_000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
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

    public function parameters(): GenerationParameters
    {
        /** @var array<int, string> $referenceImages */
        $referenceImages = $this->validated('reference_images');

        return new GenerationParameters(
            $this->string('prompt')->toString(),
            Size::from($this->string('size')->toString()),
            Quality::from($this->string('quality')->toString()),
            array_values($referenceImages),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Add at most :max reference images.',
            'reference_images.*.required' => 'Enter a reference image URL, or remove this one.',
            'reference_images.*.url' => 'Enter a full http(s) URL, e.g. https://example.com/style.png.',
        ];
    }
}
