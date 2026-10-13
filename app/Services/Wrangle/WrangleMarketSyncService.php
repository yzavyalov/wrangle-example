<?php

namespace App\Services\Wrangle;

use App\Models\Category;
use App\Models\Country;
use App\Models\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WrangleMarketSyncService
{
    private const FULL_SYNC_EVENTS = [
        'market.published',
        'market.updated',
    ];

    private const LIFECYCLE_EVENTS = [
        'market.resolving',
        'market.resolved',
        'market.cancelled',
    ];

    public function process(array $payload): Market
    {
        $event = $payload['event'] ?? null;
        $data = $payload['market'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(
                'Market payload is missing.'
            );
        }

        if (! isset($data['id'])) {
            throw new RuntimeException(
                'Market ID is missing.'
            );
        }

        if (! in_array(
            $event,
            [
                ...self::FULL_SYNC_EVENTS,
                ...self::LIFECYCLE_EVENTS,
            ],
            true
        )) {
            throw new RuntimeException(
                "Unsupported event: {$event}"
            );
        }

        return DB::transaction(
            function () use ($event, $data) {
                if ($event === 'market.published') {
                    $market = $this->publish($data);

                    return $this->freshMarket($market);
                }

                if ($event === 'market.updated') {
                    $market = $this->updateMarket($data);

                    return $this->freshMarket($market);
                }

                $market = $this->findExistingMarket(
                    $data['id']
                );

                match ($event) {
                    'market.resolving' =>
                    $this->resolving($market),

                    'market.resolved' =>
                    $this->resolve($market, $data),

                    'market.cancelled' =>
                    $this->cancel($market),
                };

                return $this->freshMarket($market);
            }
        );
    }

    private function publish(
        array $data
    ): Market {
        $market = Market::query()->firstOrNew([
            'wrangle_market_id' => $data['id'],
        ]);

        if (! $market->exists) {
            $market->public_id = (string) Str::ulid();
        }

        /*
         * market.published is the initial authoritative
         * snapshot of the market.
         */
        $this->syncMarketData(
            $market,
            $data,
            true
        );

        return $market;
    }

    private function updateMarket(
        array $data
    ): Market {
        /*
         * market.updated is only valid for a market that
         * has already been delivered to this merchant.
         */
        $market = $this->findExistingMarket(
            $data['id']
        );

        /*
         * market.updated contains a complete authoritative
         * snapshot of editable market data.
         *
         * It does NOT perform resolving, settlement or
         * cancellation.
         */
        $this->syncMarketData(
            $market,
            $data,
            false
        );

        return $market;
    }

    private function syncMarketData(
        Market $market,
        array $data,
        bool $isPublishedEvent
    ): void {
        /*
         * Content fields belong to the full snapshot.
         *
         * Lifecycle fields are controlled separately by
         * resolving/resolved/cancelled callbacks.
         */
        $attributes = [
            'source_locale' =>
                $data['source_locale'] ?? null,

            'image_url' =>
                filled($data['image_url'] ?? null)
                    ? trim((string) $data['image_url'])
                    : null,

            'finish_at' =>
                $data['finish_at'] ?? null,

            'published_at' =>
                $data['published_at'] ?? null,
        ];

        if ($isPublishedEvent) {
            $attributes['status'] = 'published';

            $attributes['resolved_at'] = null;
            $attributes['winning_outcome_id'] = null;
            $attributes['winning_pool'] = null;
            $attributes['losing_pool'] = null;
            $attributes['profit_per_dollar'] = null;
            $attributes['return_per_dollar'] = null;
        }

        $market->fill($attributes);
        $market->save();

        $this->syncTranslations(
            $market,
            $data
        );

        $this->syncOutcomes(
            $market,
            $data
        );

        $this->syncSources(
            $market,
            $data
        );

        $this->syncCategories(
            $market,
            $data
        );

        $this->syncCountries(
            $market,
            $data
        );
    }

    private function findExistingMarket(
        int|string $wrangleMarketId
    ): Market {
        $market = Market::query()
            ->where(
                'wrangle_market_id',
                $wrangleMarketId
            )
            ->first();

        if (! $market) {
            throw new RuntimeException(
                "Market {$wrangleMarketId} was not found."
            );
        }

        return $market;
    }

    private function syncTranslations(
        Market $market,
        array $data
    ): void {
        $locales = [];

        foreach (
            $data['translations'] ?? []
            as $translation
        ) {
            $locale = trim(
                (string) (
                    $translation['locale'] ?? ''
                )
            );

            $title = trim(
                (string) (
                    $translation['title'] ?? ''
                )
            );

            if (
                $locale === ''
                || $title === ''
            ) {
                continue;
            }

            $market->translations()->updateOrCreate(
                [
                    'locale' => $locale,
                ],
                [
                    'title' => $title,

                    'description' =>
                        $translation['description']
                        ?? null,
                ]
            );

            $locales[] = $locale;
        }

        /*
         * Full snapshot:
         * translations missing from the new payload
         * are no longer part of the market.
         */
        $query = $market->translations();

        if ($locales === []) {
            $query->delete();
        } else {
            $query
                ->whereNotIn('locale', $locales)
                ->delete();
        }
    }

    private function syncOutcomes(
        Market $market,
        array $data
    ): void {
        $answerIds = [];

        foreach (
            $data['answers'] ?? []
            as $answer
        ) {
            if (! isset($answer['id'])) {
                continue;
            }

            $answerId = $answer['id'];

            $outcome = $market
                ->outcomes()
                ->updateOrCreate(
                    [
                        'wrangle_answer_id' =>
                            $answerId,
                    ],
                    [
                        'sort_order' =>
                            $answer['sort_order'] ?? 0,
                    ]
                );

            /*
             * is_winner is intentionally not accepted from
             * the full market snapshot.
             *
             * market.resolved + winning_answer_id is the
             * only source of truth for the winner.
             */
            if ($market->status !== 'resolved') {
                $outcome->update([
                    'is_winner' => false,
                ]);
            }

            $this->syncOutcomeTranslations(
                $outcome,
                $answer
            );

            $answerIds[] = $answerId;
        }

        /*
         * We must not blindly delete an outcome that already
         * has local virtual bets attached to it.
         *
         * Such a structural change should be rejected rather
         * than corrupting betting history.
         */
        $removedOutcomes = $market
            ->outcomes()
            ->when(
                $answerIds !== [],
                fn ($query) =>
                $query->whereNotIn(
                    'wrangle_answer_id',
                    $answerIds
                )
            )
            ->when(
                $answerIds === [],
                fn ($query) => $query
            )
            ->get();

        foreach ($removedOutcomes as $outcome) {
            if ($outcome->virtualBets()->exists()) {
                throw new RuntimeException(
                    "Outcome {$outcome->wrangle_answer_id} "
                    . 'cannot be removed because it has bets.'
                );
            }

            $outcome->delete();
        }
    }

    private function syncOutcomeTranslations(
        $outcome,
        array $answer
    ): void {
        $locales = [];

        foreach (
            $answer['translations'] ?? []
            as $translation
        ) {
            $locale = trim(
                (string) (
                    $translation['locale'] ?? ''
                )
            );

            $title = trim(
                (string) (
                    $translation['title'] ?? ''
                )
            );

            if (
                $locale === ''
                || $title === ''
            ) {
                continue;
            }

            $outcome
                ->translations()
                ->updateOrCreate(
                    [
                        'locale' => $locale,
                    ],
                    [
                        'title' => $title,
                    ]
                );

            $locales[] = $locale;
        }

        $query = $outcome->translations();

        if ($locales === []) {
            $query->delete();
        } else {
            $query
                ->whereNotIn('locale', $locales)
                ->delete();
        }
    }

    private function syncSources(
        Market $market,
        array $data
    ): void {
        $market->sources()->delete();

        foreach (
            $data['sources'] ?? []
            as $source
        ) {
            $url = trim(
                (string) (
                    $source['url'] ?? ''
                )
            );

            if ($url === '') {
                continue;
            }

            $market->sources()->create([
                'url' => $url,

                'sort_order' =>
                    $source['sort_order'] ?? 0,
            ]);
        }
    }

    private function syncCategories(
        Market $market,
        array $data
    ): void {
        $ids = [];

        foreach (
            $data['categories'] ?? []
            as $item
        ) {
            if (! isset($item['id'])) {
                continue;
            }

            $category = Category::query()->updateOrCreate(
                [
                    'wrangle_category_id' =>
                        $item['id'],
                ],
                [
                    'name' =>
                        $item['name'] ?? '',

                    'slug' =>
                        $item['slug'] ?? null,
                ]
            );

            $ids[] = $category->id;
        }

        $market->categories()->sync($ids);
    }

    private function syncCountries(
        Market $market,
        array $data
    ): void {
        $ids = [];

        foreach (
            $data['countries'] ?? []
            as $item
        ) {
            $code = strtoupper(
                trim(
                    (string) (
                        $item['code'] ?? ''
                    )
                )
            );

            if ($code === '') {
                continue;
            }

            $country = Country::query()->updateOrCreate(
                [
                    'code' => $code,
                ],
                [
                    'name' =>
                        $item['name'] ?? $code,
                ]
            );

            $ids[] = $country->id;
        }

        $market->countries()->sync($ids);
    }

    private function resolving(
        Market $market
    ): void {
        $market->update([
            'status' => 'resolving',
        ]);
    }

    private function resolve(
        Market $market,
        array $data
    ): void {
        $winningAnswerId =
            $data['winning_answer_id'] ?? null;

        if ($winningAnswerId === null) {
            throw new RuntimeException(
                'Resolved market has no winning_answer_id.'
            );
        }

        $winner = $market
            ->outcomes()
            ->where(
                'wrangle_answer_id',
                $winningAnswerId
            )
            ->first();

        if (! $winner) {
            throw new RuntimeException(
                "Winning answer {$winningAnswerId} was not found."
            );
        }

        /*
         * winning_answer_id from market.resolved is the
         * single source of truth.
         */
        $market->outcomes()->update([
            'is_winner' => false,
        ]);

        $winner->refresh();

        $winner->update([
            'is_winner' => true,
        ]);

        /*
         * Pari-mutuel:
         *
         * profit per $1 =
         * losing pool / winning pool
         *
         * return per $1 =
         * 1 + profit per $1
         */
        $winningPool = (float) $market
            ->virtualBets()
            ->where(
                'market_outcome_id',
                $winner->id
            )
            ->where(
                'status',
                'placed'
            )
            ->sum('amount');

        $totalPool = (float) $market
            ->virtualBets()
            ->where(
                'status',
                'placed'
            )
            ->sum('amount');

        $losingPool = max(
            0,
            $totalPool - $winningPool
        );

        $profitPerDollar = null;
        $returnPerDollar = null;

        if ($winningPool > 0) {
            $profitPerDollar =
                $losingPool / $winningPool;

            $returnPerDollar =
                1 + $profitPerDollar;
        }

        $market->update([
            'status' => 'resolved',

            'resolved_at' =>
                $data['resolved_at'] ?? now(),

            'winning_outcome_id' =>
                $winner->id,

            'winning_pool' =>
                $winningPool,

            'losing_pool' =>
                $losingPool,

            'profit_per_dollar' =>
                $profitPerDollar,

            'return_per_dollar' =>
                $returnPerDollar,
        ]);

        if ($winningPool <= 0) {
            $market
                ->virtualBets()
                ->where('status', 'placed')
                ->where(
                    'market_outcome_id',
                    '!=',
                    $winner->id
                )
                ->update([
                    'status' => 'lost',
                    'profit' => 0,
                    'payout' => 0,
                ]);

            return;
        }

        $winningBets = $market
            ->virtualBets()
            ->where('status', 'placed')
            ->where(
                'market_outcome_id',
                $winner->id
            )
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

        $market
            ->virtualBets()
            ->where('status', 'placed')
            ->where(
                'market_outcome_id',
                '!=',
                $winner->id
            )
            ->update([
                'status' => 'lost',
                'profit' => 0,
                'payout' => 0,
            ]);
    }

    private function cancel(
        Market $market
    ): void {
        $market->update([
            'status' => 'cancelled',

            'resolved_at' => null,

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
         * Return the virtual stake in full.
         */
        $bets = $market
            ->virtualBets()
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

    private function freshMarket(
        Market $market
    ): Market {
        return $market->fresh([
            'translations',
            'outcomes.translations',
            'sources',
            'categories',
            'countries',
            'winningOutcome',
        ]);
    }
}
