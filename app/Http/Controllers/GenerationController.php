<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use Symfony\Component\HttpFoundation\Response;

class GenerationController extends Controller
{
    /**
     * Precognitive requests validate the generate form live and never reach
     * this method. Submitting a Generation arrives with ticket #6.
     */
    public function store(StoreGenerationRequest $request): never
    {
        abort(Response::HTTP_NOT_IMPLEMENTED);
    }
}
