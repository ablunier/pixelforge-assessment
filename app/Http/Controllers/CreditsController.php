<?php

namespace App\Http\Controllers;

use App\Actions\TopUp;
use App\Enums\CreditPack;
use App\Http\Requests\BuyCreditsRequest;
use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    /** How many of a user's newest ledger entries the page lists. */
    public const int HISTORY_LIMIT = 100;

    /**
     * The Buy credits page: the packs, and the user's ledger entries, newest first.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packs' => array_map(fn (CreditPack $pack): array => [
                'id' => $pack->value,
                'amount' => $pack->amount()->nanodollars(),
            ], CreditPack::cases()),
            'ledgerEntries' => LedgerEntryResource::collection(
                $request->user()->ledgerEntries()->with('generation:id,prompt')->latest()->latest('id')->limit(self::HISTORY_LIMIT)->get(),
            )->resolve($request),
        ]);
    }

    /**
     * Buy a pack: a fake purchase that adds its Credits at once, with no payment.
     */
    public function store(BuyCreditsRequest $request, TopUp $topUp): RedirectResponse
    {
        $topUp->handle($request->user(), $request->pack()->amount());

        return to_route('credits.show');
    }
}
