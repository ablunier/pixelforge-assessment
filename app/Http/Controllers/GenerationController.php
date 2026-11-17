<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GenerationController extends Controller
{
    /**
     * Generate. Precognitive requests validate the form live and never reach
     * this method.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle(
            $request->user(),
            $request->prompt(),
            $request->size(),
            $request->quality(),
            $request->referenceImages(),
        );

        return to_route('dashboard');
    }

    /**
     * Try again: a new Generation with the failed one's parameters, through
     * the same submission path as Generate. The failed one stays as it is.
     */
    public function tryAgain(Request $request, Generation $generation, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle(
            $request->user(),
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );

        return to_route('dashboard');
    }
}
