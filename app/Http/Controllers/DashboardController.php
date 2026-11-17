<?php

namespace App\Http\Controllers;

use App\Enums\Quality;
use App\Enums\Size;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\Pricing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The main page: the generate form, priced live from the current rates,
     * above the user's Generations, newest first. The history polls this page
     * for `generations` and `pollHistory` only, so the pricing props are lazy
     * and a poll never asks Pixelforge for rates.
     */
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'pricing' => fn (): array => [
                'rates' => $pixelforge->rates(),
                'tokens' => Pricing::tokenRules(),
            ],
            'priceRange' => fn () => Pricing::startingPriceRange($pixelforge->rates()),
            'options' => [
                'sizes' => array_column(Size::cases(), 'value'),
                'qualities' => array_column(Quality::cases(), 'value'),
                'defaultSize' => Size::DEFAULT->value,
                'defaultQuality' => Quality::DEFAULT->value,
                'maxPromptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'generations' => fn (): array => GenerationResource::collection(
                $user->generations()->history()->get(),
            )->resolve($request),
            // The history polls for status updates only while this is true.
            'pollHistory' => fn (): bool => $user->generations()->notFinal()->exists(),
        ]);
    }
}
