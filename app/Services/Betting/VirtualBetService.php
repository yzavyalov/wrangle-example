<?php

namespace App\Services\Betting;

use App\Models\Market;
use App\Models\VirtualBet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VirtualBetService
{
    public function placeBet(
        string $marketPublicId,
        int $outcomeIndex,
        string $visitorId,
        float $amount
    ): VirtualBet {
        return DB::transaction(function () use (
            $marketPublicId,
            $outcomeIndex,
            $visitorId,
            $amount
        ) {
            $market = Market::query()
                ->where(
                    'public_id',
                    $marketPublicId
                )
                ->lockForUpdate()
                ->first();

            if (! $market) {
                throw ValidationException::withMessages([
                    'betAmount' =>
                        'The market is no longer available.',
                ]);
            }

            if (! $this->marketAcceptsBets($market)) {
                throw ValidationException::withMessages([
                    'betAmount' =>
                        'Betting on this market has ended.',
                ]);
            }

            $outcomes = $market
                ->outcomes()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $outcome = $outcomes
                ->values()
                ->get($outcomeIndex);

            if (! $outcome) {
                throw ValidationException::withMessages([
                    'betAmount' =>
                        'The selected outcome is no longer available.',
                ]);
            }

            return VirtualBet::query()->create([
                'market_id' =>
                    $market->id,

                'market_outcome_id' =>
                    $outcome->id,

                'visitor_id' =>
                    $visitorId,

                'amount' =>
                    round($amount, 2),

                'status' =>
                    'placed',

                'profit' =>
                    null,

                'payout' =>
                    null,
            ]);
        });
    }

    public function marketAcceptsBets(
        Market $market
    ): bool {
        if ($market->status !== 'published') {
            return false;
        }

        if (
            $market->finish_at
            && $market->finish_at->isPast()
        ) {
            return false;
        }

        return true;
    }
}
