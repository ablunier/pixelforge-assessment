<?php

namespace App\Http\Controllers;

use App\Enums\Quality;
use App\Enums\Size;
use App\Http\Requests\StoreGenerationRequest;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\Pricing;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The main page: the generate form, priced live from the current rates.
     */
    public function __invoke(PixelforgeClient $pixelforge): Response
    {
        $rates = $pixelforge->rates();

        return Inertia::render('Dashboard', [
            'pricing' => [
                'rates' => $rates,
                'tokens' => Pricing::tokenRules(),
            ],
            'priceRange' => Pricing::priceRange(0, Size::DEFAULT, Quality::DEFAULT, 0, $rates),
            'options' => [
                'sizes' => array_column(Size::cases(), 'value'),
                'qualities' => array_column(Quality::cases(), 'value'),
                'defaultSize' => Size::DEFAULT->value,
                'defaultQuality' => Quality::DEFAULT->value,
                'maxPromptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
        ]);
    }
}
