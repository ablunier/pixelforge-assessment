<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Credits\InsufficientAvailableBalance;
use App\Generations\GenerationParameters;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Requests\TryAgainRequest;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
    public function __construct(private readonly CreateGeneration $createGeneration) {}

    /**
     * Generate. Precognitive requests validate the form live and never reach
     * this method.
     */
    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        $this->create($request->user(), $request->parameters());

        return to_route('dashboard');
    }

    /**
     * Try again: a new Generation with the failed one's parameters, through
     * the same submission path as Generate. The failed one stays as it is.
     */
    public function tryAgain(TryAgainRequest $request, Generation $generation): RedirectResponse
    {
        $this->create($request->user(), $generation->parameters());

        return to_route('dashboard');
    }

    /**
     * @throws ValidationException under `generate` when there are no rates to
     *                             price it with, or the Available Balance can't cover its Maximum Price
     */
    private function create(User $user, GenerationParameters $parameters): void
    {
        try {
            $this->createGeneration->handle($user, $parameters);
        } catch (PricingUnavailable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'generate' => "Prices can't be loaded right now, so nothing was submitted. Try again in a minute.",
            ]);
        } catch (InsufficientAvailableBalance $exception) {
            throw ValidationException::withMessages([
                'generate' => "Not enough credits: \${$exception->availableBalance->formatForDisplay()} available, up to \${$exception->maximumPrice->formatForDisplay()} needed. Buy credits to continue.",
            ]);
        }
    }
}
