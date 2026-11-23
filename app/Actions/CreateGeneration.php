<?php

namespace App\Actions;

use App\Credits\InsufficientAvailableBalance;
use App\Generations\GenerationParameters;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingUnavailable;

/**
 * Generate: open the Generation at Pixelforge's current rates, holding its
 * Maximum Price from the Available Balance, and queue its submission to
 * Pixelforge (ADR-0003).
 */
final readonly class CreateGeneration
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private GenerationLifecycle $lifecycle,
    ) {}

    /**
     * @throws PricingUnavailable when there are no rates to price it with; nothing is saved
     * @throws InsufficientAvailableBalance when the user's Available Balance can't cover its Maximum Price; nothing is saved
     */
    public function handle(User $user, GenerationParameters $parameters): Generation
    {
        $generation = $this->lifecycle->open($user, $parameters, $this->pixelforge->rates());

        SubmitGenerationToPixelforge::dispatch($generation);

        return $generation;
    }
}
