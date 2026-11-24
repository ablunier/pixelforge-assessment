<?php

namespace App\Http\Controllers;

use App\Actions\TopUp;
use App\Enums\CreditPack;
use App\Http\Requests\BuyCreditsRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    /**
     * The Buy credits page: the packs, and one page of the user's ledger
     * entries, newest first.
     */
    public function show(Request $request): Response
    {
        $history = $request->user()->ledgerEntries()->history()->paginate(LedgerEntry::HISTORY_PAGE_SIZE);

        return Inertia::render('settings/Credits', [
            'packs' => array_map(fn (CreditPack $pack): array => [
                'id' => $pack->value,
                'amount' => $pack->amount()->nanodollars(),
            ], CreditPack::cases()),
            'ledgerEntries' => LedgerEntryResource::collection($history->getCollection())->resolve($request),
            'ledgerPage' => ['current' => $history->currentPage(), 'last' => $history->lastPage()],
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
