<?php

namespace App\Services\Wrangle;

use App\Models\Category;
use App\Models\Country;
use App\Models\Market;
use App\Models\MarketOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WrangleMarketSyncService
{
    public function process(array $payload): Market
    {
        $event = $payload['event'] ?? null;
        $data = $payload['market'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Market payload is missing.');
        }

        if (! in_array($event, [
            'market.published',
            'market.resolving',
            'market.resolved',
            'market.cancelled',
        ], true)) {
            throw new RuntimeException("Unsupported event: {$event}");
        }

        return DB::transaction(function () use ($event, $data) {
            $market = $this->syncMarket($data);

            $this->syncTranslations($market, $data);
            $this->syncOutcomes($market, $data);
            $this->syncSources($market, $data);
            $this->syncCategories($market, $data);
            $this->syncCountries($market, $data);

            match ($event) {
                'market.published' => null,
                'market.resolving' => $this->resolving($market),
                'market.resolved' => $this->resolve($market, $data),
                'market.cancelled' => $this->cancel($market),
            };

            return $market->fresh([
                'translations',
                'outcomes.translations',
                'sources',
                'categories',
                'countries',
                'winningOutcome',
            ]);
        });
    }

    private function syncMarket(array $data): Market
    {
        if (! isset($data['id'])) {
            throw new RuntimeException('Market ID is missing.');
        }

        $market = Market::query()->firstOrNew([
            'wrangle_market_id' => $data['id'],
        ]);

        if (! $market->exists) {
            $market->public_id = (string) Str::ulid();
        }

        $market->fill([
            'status' => $data['status'] ?? 'published',
            'source_locale' => $data['source_locale'] ?? null,
            'finish_at' => $data['finish_at'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'resolved_at' => $data['resolved_at'] ?? null,
        ]);

        $market->save();

        return $market;
    }

    private function syncTranslations(Market $market, array $data): void
    {
        foreach ($data['translations'] ?? [] as $translation) {
            if (empty($translation['locale']) || empty($translation['title'])) {
                continue;
            }

            $market->translations()->updateOrCreate(
                [
                    'locale' => $translation['locale'],
                ],
                [
                    'title' => $translation['title'],
                    'description' => $translation['description'] ?? null,
                ]
            );
        }
    }

    private function syncOutcomes(Market $market, array $data): void
    {
        foreach ($data['answers'] ?? [] as $answer) {
            if (! isset($answer['id'])) {
                continue;
            }

            $outcome = $market->outcomes()->updateOrCreate(
                [
                    'wrangle_answer_id' => $answer['id'],
                ],
                [
                    'sort_order' => $answer['sort_order'] ?? 0,
                    'is_winner' => (bool) ($answer['is_winner'] ?? false),
                ]
            );

            foreach ($answer['translations'] ?? [] as $translation) {
                if (empty($translation['locale']) || empty($translation['title'])) {
                    continue;
                }

                $outcome->translations()->updateOrCreate(
                    [
                        'locale' => $translation['locale'],
                    ],
                    [
                        'title' => $translation['title'],
                    ]
                );
            }
        }
    }

    private function syncSources(Market $market, array $data): void
    {
        $market->sources()->delete();

        foreach ($data['sources'] ?? [] as $source) {
            if (empty($source['url'])) {
                continue;
            }

            $market->sources()->create([
                'url' => $source['url'],
                'sort_order' => $source['sort_order'] ?? 0,
            ]);
        }
    }

    private function syncCategories(Market $market, array $data): void
    {
        $ids = [];

        foreach ($data['categories'] ?? [] as $item) {
            if (! isset($item['id'])) {
                continue;
            }

            $category = Category::query()->updateOrCreate(
                ['wrangle_category_id' => $item['id']],
                [
                    'name' => $item['name'] ?? '',
                    'slug' => $item['slug'] ?? null,
                ]
            );

            $ids[] = $category->id;
        }

        $market->categories()->sync($ids);
    }

    private function syncCountries(Market $market, array $data): void
    {
        $ids = [];

        foreach ($data['countries'] ?? [] as $item) {
            $code = strtoupper(trim((string) ($item['code'] ?? '')));

            if ($code === '') {
                continue;
            }

            $country = Country::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $item['name'] ?? $code,
                ]
            );

            $ids[] = $country->id;
        }

        $market->countries()->sync($ids);
    }

    private function resolving(Market $market): void
    {
        $market->update([
            'status' => 'resolving',
        ]);
    }

    private function resolve(Market $market, array $data): void
    {
        $winningAnswerId = $data['winning_answer_id'] ?? null;

        if ($winningAnswerId === null) {
            throw new RuntimeException(
                'Resolved market has no winning_answer_id.'
            );
        }

        $winner = $market->outcomes()
            ->where('wrangle_answer_id', $winningAnswerId)
            ->first();

        if (! $winner) {
            throw new RuntimeException(
                "Winning answer {$winningAnswerId} was not found."
            );
        }

        $market->outcomes()->update([
            'is_winner' => false,
        ]);

        $winner->update([
            'is_winner' => true,
        ]);

        /*
         * IMPORTANT:
         *
         * User-defined pari-mutuel calculation:
         *
         * profit per $1 =
         *     losing pool / winning pool
         *
         * return per $1 =
         *     1 + profit per $1
         */

        $winningPool = (float) $market->virtualBets()
            ->where('market_outcome_id', $winner->id)
            ->where('status', 'placed')
            ->sum('amount');

        $totalPool = (float) $market->virtualBets()
            ->where('status', 'placed')
            ->sum('amount');

        $losingPool = max(0, $totalPool - $winningPool);

        $profitPerDollar = null;
        $returnPerDollar = null;

        if ($winningPool > 0) {
            $profitPerDollar = $losingPool / $winningPool;
            $returnPerDollar = 1 + $profitPerDollar;
        }

        $market->update([
            'status' => 'resolved',
            'resolved_at' => $data['resolved_at'] ?? now(),
            'winning_outcome_id' => $winner->id,
            'winning_pool' => $winningPool,
            'losing_pool' => $losingPool,
            'profit_per_dollar' => $profitPerDollar,
            'return_per_dollar' => $returnPerDollar,
        ]);

        /*
         * winning_pool == 0:
         * никто не поставил на победителя.
         *
         * Не придумываем распределение losing pool.
         */
        if ($winningPool <= 0) {
            $market->virtualBets()
                ->where('status', 'placed')
                ->where('market_outcome_id', '!=', $winner->id)
                ->update([
                    'status' => 'lost',
                    'profit' => 0,
                    'payout' => 0,
                ]);

            return;
        }

        $winningBets = $market->virtualBets()
            ->where('status', 'placed')
            ->where('market_outcome_id', $winner->id)
            ->get();

        foreach ($winningBets as $bet) {
            $amount = (float) $bet->amount;

            $profit = round(
                $amount * $profitPerDollar,
                2
            );

            $payout = round(
                $amount + $profit,
                2
            );

            $bet->update([
                'status' => 'won',
                'profit' => $profit,
                'payout' => $payout,
            ]);
        }

        $market->virtualBets()
            ->where('status', 'placed')
            ->where('market_outcome_id', '!=', $winner->id)
            ->update([
                'status' => 'lost',
                'profit' => 0,
                'payout' => 0,
            ]);
    }

    private function cancel(Market $market): void
    {
        $market->update([
            'status' => 'cancelled',
            'winning_outcome_id' => null,
            'winning_pool' => null,
            'losing_pool' => null,
            'profit_per_dollar' => null,
            'return_per_dollar' => null,
        ]);

        $market->outcomes()->update([
            'is_winner' => false,
        ]);

        /*
         * Cancelled market:
         * virtual stake is returned in full.
         */
        $bets = $market->virtualBets()
            ->where('status', 'placed')
            ->get();

        foreach ($bets as $bet) {
            $bet->update([
                'status' => 'refunded',
                'profit' => 0,
                'payout' => $bet->amount,
            ]);
        }
    }
}
