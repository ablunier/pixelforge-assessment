<?php

namespace App\Http\Controllers;

use App\Http\Resources\GenerationResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    /**
     * The History page: the user's Generations, newest first. It polls itself
     * for `generations` and `pollHistory` while any of them isn't final.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('History', [
            'generations' => fn (): array => GenerationResource::collection(
                $user->generations()->history()->get(),
            )->resolve($request),
            // The history polls for status updates only while this is true.
            'pollHistory' => fn (): bool => $user->generations()->notFinal()->exists(),
        ]);
    }
}
