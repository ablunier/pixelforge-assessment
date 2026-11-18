<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingUnavailable;
use App\Pricing\Pricing;
use Illuminate\Validation\ValidationException;

/**
 * Generate: save the Generation locally as `pending` with its Maximum Price,
 * and queue its submission to Pixelforge (ADR-0003).
 */
final readonly class CreateGeneration
{
    public function __construct(private PixelforgeClient $pixelforge) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws ValidationException under `generate` when there are no rates to price it with
     */
    public function handle(User $user, string $prompt, Size $size, Quality $quality, array $referenceImages): Generation
    {
        try {
            $rates = $this->pixelforge->rates();
        } catch (PricingUnavailable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'generate' => "Prices can't be loaded right now, so nothing was submitted. Try again in a minute.",
            ]);
        }

        $priceRange = Pricing::priceRange(mb_strlen($prompt), $size, $quality, count($referenceImages), $rates);

        $generation = $user->generations()->create([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'status' => GenerationStatus::Pending,
            'max_price' => $priceRange->max,
        ]);

        SubmitGenerationToPixelforge::dispatch($generation);

        return $generation;
    }
}
