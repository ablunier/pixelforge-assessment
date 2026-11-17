<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;

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
}
