<?php

namespace App\Http\Controllers;

use App\Enums\Quality;
use App\Enums\Size;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingUnavailable;
use App\Pricing\PriceRange;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The main page: the generate form, priced live from the current rates
     * beside the user's Available Balance, above the user's Generations,
     * newest first. The history polls this page for `generations`,
     * `pollHistory` and the balances only, so the pricing props are lazy
     * and a poll never asks Pixelforge for rates. When no rates can be had,
     * the pricing props are null and the history still shows.
     */
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $user = $request->user();
        // Asked for at most once per request, and only when a pricing prop is resolved.
        $resolved = false;
        $rates = function () use (&$resolved, $pixelforge): ?Rates {
            if ($resolved === false) {
                $resolved = $this->ratesOrNull($pixelforge);
            }

            return $resolved;
        };

        return Inertia::render('Dashboard', [
            'pricing' => fn (): ?array => ($rates() === null) ? null : [
                'rates' => $rates(),
                'tokens' => Pricing::tokenRules(),
                'markup' => Pricing::markup(),
            ],
            'priceRange' => fn (): ?PriceRange => ($rates() === null) ? null : Pricing::startingPriceRange($rates()),
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
            // In nanodollars, beside the generate button. The history polls it
            // too, so released Holds show up without a reload.
            'availableBalance' => fn (): int => $user->availableBalance()->nanodollars(),
            // The history polls for status updates only while this is true.
            'pollHistory' => fn (): bool => $user->generations()->notFinal()->exists(),
        ]);
    }

    private function ratesOrNull(PixelforgeClient $pixelforge): ?Rates
    {
        try {
            return $pixelforge->rates();
        } catch (PricingUnavailable $exception) {
            report($exception);

            return null;
        }
    }
}
