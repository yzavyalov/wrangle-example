<?php

use App\Models\Market;
use App\Models\VirtualBet;
use App\Services\Betting\VirtualBetService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    /**
     * Maximum virtual bet amount.
     */
    private const MAX_BET_AMOUNT = 10000;

    /**
     * Market statuses visible on the storefront.
     */
    private const MARKET_STATUSES = [
        'published',
        'resolving',
        'resolved',
    ];

    public string $publicId;

    public string $locale = 'en';

    /*
     * Bet modal state.
     */
    public bool $betModalOpen = false;

    public ?int $betOutcomeIndex = null;

    public string $betMarketTitle = '';

    public string $betOutcomeTitle = '';

    public string $betAmount = '';

    public ?string $betSuccessMessage = null;

    /**
     * Make sure the requested market exists and is public.
     */
    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;

        abort_unless(
            Market::query()
                ->where('public_id', $this->publicId)
                ->whereIn(
                    'status',
                    self::MARKET_STATUSES
                )
                ->exists(),
            404
        );
    }

    /**
     * Current market.
     */
    public function getMarketProperty(): Market
    {
        $locale = $this->validLocale();

        return Market::query()
            ->where(
                'public_id',
                $this->publicId
            )
            ->whereIn(
                'status',
                self::MARKET_STATUSES
            )
            ->with([
                'translations' => function ($query) use ($locale) {
                    $query->whereIn(
                        'locale',
                        array_unique([
                            $locale,
                            'en',
                        ])
                    );
                },

                'categories',

                'countries',

                'outcomes' => function ($query) use ($locale) {
                    $query
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'translations' => function ($translationQuery) use ($locale) {
                                $translationQuery->whereIn(
                                    'locale',
                                    array_unique([
                                        $locale,
                                        'en',
                                    ])
                                );
                            },
                        ])
                        ->withSum(
                            'virtualBets as bet_pool',
                            'amount'
                        );
                },

                'winningOutcome.translations',
            ])
            ->withCount([
                'virtualBets as bets_count',
            ])
            ->firstOrFail();
    }

    /**
     * Bets made by the current anonymous visitor
     * on this market.
     *
     * Viewing a market does not create a visitor ID.
     */
    public function getMyBetsProperty(): Collection
    {
        $visitorId = session()->get(
            'wrangle_visitor_id'
        );

        if (
            ! is_string($visitorId)
            || ! Str::isUlid($visitorId)
        ) {
            return new Collection();
        }

        $marketId = Market::query()
            ->where(
                'public_id',
                $this->publicId
            )
            ->whereIn(
                'status',
                self::MARKET_STATUSES
            )
            ->value('id');

        if (! $marketId) {
            return new Collection();
        }

        return VirtualBet::query()
            ->where(
                'market_id',
                $marketId
            )
            ->where(
                'visitor_id',
                $visitorId
            )
            ->with([
                'outcome.translations',
            ])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Count unique visitors who placed bets.
     */
    public function traderCount(
        Market $market
    ): int {
        return (int) VirtualBet::query()
            ->where(
                'market_id',
                $market->id
            )
            ->distinct()
            ->count('visitor_id');
    }

    private function validLocale(): string
    {
        return in_array(
            $this->locale,
            [
                'en',
                'es',
            ],
            true
        )
            ? $this->locale
            : 'en';
    }

    public function marketTitle(
        Market $market
    ): string {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere(
                'locale',
                $locale
            )
            ?->title
            ?? $market->translations
            ->firstWhere(
                'locale',
                'en'
            )
            ?->title
            ?? 'Untitled market';
    }

    public function marketDescription(
        Market $market
    ): ?string {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere(
                'locale',
                $locale
            )
            ?->description
            ?? $market->translations
                ->firstWhere(
                    'locale',
                    'en'
                )
                ?->description;
    }

    public function outcomeTitle(
        $outcome
    ): string {
        $locale = $this->validLocale();

        return $outcome->translations
            ->firstWhere(
                'locale',
                $locale
            )
            ?->title
            ?? $outcome->translations
            ->firstWhere(
                'locale',
                'en'
            )
            ?->title
            ?? 'Outcome';
    }

    /**
     * UI check only.
     *
     * VirtualBetService performs the authoritative
     * check again when the bet is actually created.
     */
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

    public function marketPool(
        Market $market
    ): float {
        return (float) $market->outcomes->sum(
            fn ($outcome) =>
            (float) (
                $outcome->bet_pool ?? 0
            )
        );
    }

    public function outcomePool(
        $outcome
    ): float {
        return (float) (
            $outcome->bet_pool ?? 0
        );
    }

    public function outcomePercent(
        Market $market,
               $outcome
    ): float {
        $total =
            $this->marketPool($market);

        if ($total <= 0) {
            return 0;
        }

        return round(
            (
                $this->outcomePool($outcome)
                / $total
            ) * 100,
            1
        );
    }

    public function betCount(
        Market $market
    ): int {
        return (int) (
            $market->bets_count ?? 0
        );
    }

    public function categoryName(
        Market $market
    ): string {
        return $market->categories
            ->first()
            ?->name
            ?? 'Market';
    }

    /**
     * Profit for every $1 staked on the winner.
     *
     * Example:
     * winning pool = 6300
     * losing pool  = 3700
     *
     * profit = 3700 / 6300
     */
    public function resolvedProfitPerDollar(
        Market $market
    ): ?float {
        if (
            $market->status !== 'resolved'
        ) {
            return null;
        }

        if (
            $market->profit_per_dollar !== null
        ) {
            return (float)
            $market->profit_per_dollar;
        }

        $winningPool = (float) (
            $market->winning_pool ?? 0
        );

        $losingPool = (float) (
            $market->losing_pool ?? 0
        );

        if ($winningPool <= 0) {
            return null;
        }

        return $losingPool / $winningPool;
    }

    public function statusLabel(
        Market $market
    ): string {
        return match ($market->status) {
            'published' =>
            'Open',

            'resolving' =>
            'Resolving',

            'resolved' =>
            'Resolved',

            default =>
            ucfirst($market->status),
        };
    }

    public function timeLeft(
        Market $market
    ): string {
        if (! $market->finish_at) {
            return 'No deadline';
        }

        if (
            $market->status === 'resolved'
        ) {
            return 'Finished';
        }

        if (
            $market->status === 'resolving'
        ) {
            return 'Betting closed';
        }

        if (
            $market->finish_at->isPast()
        ) {
            return 'Ended';
        }

        $seconds = now()->diffInSeconds(
            $market->finish_at
        );

        $days = intdiv(
            $seconds,
            86400
        );

        $hours = intdiv(
            $seconds % 86400,
            3600
        );

        $minutes = intdiv(
            $seconds % 3600,
            60
        );

        if ($days > 0) {
            return $days
                .'d '
                .$hours
                .'h left';
        }

        if ($hours > 0) {
            return $hours
                .'h '
                .$minutes
                .'m left';
        }

        return max(
                1,
                $minutes
            ).'m left';
    }

    /**
     * Open bet modal.
     *
     * We receive only the position of the outcome,
     * never expose the internal outcome database ID.
     */
    public function openBetModal(
        int $outcomeIndex
    ): void {
        $this->resetValidation();

        $this->betSuccessMessage = null;

        $market = Market::query()
            ->where(
                'public_id',
                $this->publicId
            )
            ->whereIn(
                'status',
                self::MARKET_STATUSES
            )
            ->with([
                'translations',

                'outcomes' => function ($query) {
                    $query
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with('translations');
                },
            ])
            ->first();

        if (! $market) {
            return;
        }

        if (
            ! $this->marketAcceptsBets(
                $market
            )
        ) {
            return;
        }

        $outcome = $market
            ->outcomes
            ->values()
            ->get($outcomeIndex);

        if (! $outcome) {
            return;
        }

        $this->betOutcomeIndex =
            $outcomeIndex;

        $this->betMarketTitle =
            $this->marketTitle($market);

        $this->betOutcomeTitle =
            $this->outcomeTitle($outcome);

        $this->betAmount = '';

        $this->betModalOpen = true;
    }

    public function closeBetModal(): void
    {
        $this->betModalOpen = false;

        $this->betOutcomeIndex = null;

        $this->betMarketTitle = '';

        $this->betOutcomeTitle = '';

        $this->betAmount = '';

        $this->resetValidation();
    }

    /**
     * Validate the Livewire form and delegate
     * bet creation to VirtualBetService.
     */
    public function placeBet(): void
    {
        $this->validate([
            'betAmount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:'.self::MAX_BET_AMOUNT,
            ],
        ], [
            'betAmount.required' =>
                'Enter the amount you want to bet.',

            'betAmount.numeric' =>
                'The bet amount must be a number.',

            'betAmount.min' =>
                'The minimum bet is $0.01.',

            'betAmount.max' =>
                'The maximum bet is $10,000.',
        ]);

        if (
            $this->betOutcomeIndex === null
        ) {
            throw ValidationException::withMessages([
                'betAmount' =>
                    'The selected outcome is no longer available.',
            ]);
        }

        $amount = round(
            (float) $this->betAmount,
            2
        );

        $marketTitle =
            $this->betMarketTitle;

        $outcomeTitle =
            $this->betOutcomeTitle;

        app(VirtualBetService::class)->placeBet(
            marketPublicId:
            $this->publicId,

            outcomeIndex:
            $this->betOutcomeIndex,

            visitorId:
            $this->visitorId(),

            amount:
            $amount,
        );

        $this->betModalOpen = false;

        $this->betOutcomeIndex = null;

        $this->betAmount = '';

        $this->resetValidation();

        $this->betSuccessMessage =
            'Bet accepted: $'
            .number_format($amount, 2)
            .' on '
            .$outcomeTitle
            .' — '
            .$marketTitle.'.';
    }

    /**
     * Anonymous visitor identifier.
     */
    private function visitorId(): string
    {
        $visitorId = session()->get(
            'wrangle_visitor_id'
        );

        if (
            is_string($visitorId)
            && Str::isUlid($visitorId)
        ) {
            return $visitorId;
        }

        $visitorId =
            (string) Str::ulid();

        session()->put(
            'wrangle_visitor_id',
            $visitorId
        );

        return $visitorId;
    }
};
?>

<div class="min-h-screen bg-[#06111f] text-white">

    {{-- =========================================================
         HEADER
    ========================================================== --}}
    <header class="border-b border-slate-800/80 bg-[#071321]/95">

        <div
            class="mx-auto flex max-w-[1400px] items-center gap-6 px-5 py-4 lg:px-8"
        >

            <a
                href="/"
                class="flex shrink-0 items-center gap-3"
            >

                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-xl font-black shadow-lg shadow-blue-600/20"
                >
                    W
                </div>

                <div class="text-xl font-black tracking-wide">
                    WRANGLE
                </div>

            </a>


            <div class="ml-auto flex items-center gap-4">

                <select
                    wire:model.live="locale"
                    wire:loading.attr="disabled"
                    class="
                        rounded-xl
                        border border-slate-800
                        bg-slate-900
                        px-3 py-2.5
                        text-sm text-slate-200
                        outline-none
                        transition
                        hover:border-slate-700
                        focus:border-blue-500
                    "
                    aria-label="Language"
                >
                    <option value="en">EN</option>
                    <option value="es">ES</option>
                </select>


                <a
                    href="/"
                    class="
                        hidden text-sm font-semibold
                        text-slate-400 transition
                        hover:text-white sm:block
                    "
                >
                    ← Back to markets
                </a>

            </div>

        </div>

    </header>


    <main
        class="mx-auto max-w-[1400px] px-5 py-8 lg:px-8"
    >

        @php
            $market = $this->market;

            $pool =
                $this->marketPool($market);

            $acceptsBets =
                $this->marketAcceptsBets(
                    $market
                );

            $profitPerDollar =
                $this->resolvedProfitPerDollar(
                    $market
                );

            $statusClass = match ($market->status) {
                'published' =>
                    'bg-emerald-400 text-emerald-950',

                'resolving' =>
                    'bg-amber-300 text-amber-950',

                'resolved' =>
                    'bg-violet-400 text-violet-950',

                default =>
                    'bg-slate-500 text-white',
            };
        @endphp


        {{-- =====================================================
             BACK
        ====================================================== --}}
        <div class="mb-5">

            <a
                href="/"
                class="
                    text-sm font-semibold
                    text-blue-400 transition
                    hover:text-blue-300
                "
            >
                ← All markets
            </a>

        </div>


        {{-- =====================================================
             SUCCESS MESSAGE
        ====================================================== --}}
        @if ($betSuccessMessage)

            <div
                class="
                    mb-6 flex items-start justify-between gap-4
                    rounded-2xl
                    border border-emerald-500/30
                    bg-emerald-500/10
                    px-5 py-4
                    text-sm text-emerald-200
                "
            >

                <div>

                    <span
                        class="mr-2 font-black text-emerald-400"
                    >
                        ✓
                    </span>

                    {{ $betSuccessMessage }}

                </div>


                <button
                    type="button"
                    wire:click="$set('betSuccessMessage', null)"
                    class="
                        text-lg leading-none
                        text-emerald-300
                        transition hover:text-white
                    "
                >
                    ×
                </button>

            </div>

        @endif


        {{-- =====================================================
             MARKET HERO
        ====================================================== --}}
        <section
            class="
                overflow-hidden rounded-3xl
                border border-slate-800
                bg-[#0a1929]
                shadow-2xl shadow-black/20
            "
        >

            <div
                class="
                    relative min-h-[340px]
                    overflow-hidden
                    border-b border-slate-800
                    bg-gradient-to-br
                    from-blue-950
                    via-[#102b4b]
                    to-[#071422]
                "
            >

                @if ($market->image_url)

                    <img
                        src="{{ $market->image_url }}"
                        alt=""
                        referrerpolicy="no-referrer"
                        class="
                            absolute inset-0
                            h-full w-full
                            object-cover
                        "
                        onerror="this.style.display='none'"
                    >


                    <div
                        class="
                            absolute inset-0
                            bg-gradient-to-r
                            from-[#06111f]/95
                            via-[#06111f]/75
                            to-[#06111f]/30
                        "
                    ></div>


                    <div
                        class="
                            absolute inset-0
                            bg-gradient-to-t
                            from-[#06111f]/95
                            via-transparent
                            to-black/30
                        "
                    ></div>

                @else

                    <div
                        class="
                            absolute -right-20 -top-28
                            h-96 w-96
                            rounded-full
                            bg-blue-500/20
                            blur-3xl
                        "
                    ></div>


                    <div
                        class="
                            absolute bottom-0 left-1/3
                            h-40 w-80
                            rounded-full
                            bg-cyan-400/10
                            blur-3xl
                        "
                    ></div>

                @endif


                <div
                    class="
                        relative z-10
                        flex min-h-[340px]
                        flex-col justify-between
                        p-6 md:p-9
                    "
                >

                    <div
                        class="flex flex-wrap items-center gap-3"
                    >

                        <span
                            class="
                                rounded-full
                                border border-blue-400/40
                                bg-[#06111f]/75
                                px-3 py-1.5
                                text-xs font-semibold
                                text-slate-100
                                backdrop-blur-md
                            "
                        >
                            {{ $this->categoryName($market) }}
                        </span>


                        <span
                            class="
                                rounded-full
                                px-3 py-1.5
                                text-xs font-black
                                {{ $statusClass }}
                            "
                        >
                            {{ $this->statusLabel($market) }}
                        </span>


                        <span
                            class="
                                rounded-full
                                border border-white/10
                                bg-black/50
                                px-3 py-1.5
                                text-xs font-medium
                                text-slate-100
                                backdrop-blur-md
                            "
                        >
                            ◷ {{ $this->timeLeft($market) }}
                        </span>

                    </div>


                    <div class="max-w-4xl">

                        <h1
                            class="
                                text-3xl font-black
                                leading-tight tracking-tight
                                md:text-5xl
                            "
                        >
                            {{ $this->marketTitle($market) }}
                        </h1>


                        @if ($this->marketDescription($market))

                            <p
                                class="
                                    mt-5 max-w-3xl
                                    text-base leading-7
                                    text-slate-300
                                    md:text-lg
                                "
                            >
                                {{ $this->marketDescription($market) }}
                            </p>

                        @endif

                    </div>

                </div>

            </div>


            {{-- =================================================
                 MARKET STATS
            ================================================== --}}
            <div
                class="
                    grid grid-cols-2
                    border-b border-slate-800
                    md:grid-cols-4
                "
            >

                <div
                    class="
                        border-r border-slate-800
                        p-5
                    "
                >

                    <div
                        class="
                            text-xs font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Volume
                    </div>

                    <div
                        class="mt-1 text-xl font-black text-white"
                    >
                        ${{ number_format($pool, 2) }}
                    </div>

                </div>


                <div
                    class="
                        p-5
                        md:border-r md:border-slate-800
                    "
                >

                    <div
                        class="
                            text-xs font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Bets
                    </div>

                    <div
                        class="mt-1 text-xl font-black text-white"
                    >
                        {{ number_format(
                            $this->betCount($market)
                        ) }}
                    </div>

                </div>


                <div
                    class="
                        border-r border-t
                        border-slate-800
                        p-5
                        md:border-t-0
                    "
                >

                    <div
                        class="
                            text-xs font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Traders
                    </div>

                    <div
                        class="mt-1 text-xl font-black text-white"
                    >
                        {{ number_format(
                            $this->traderCount($market)
                        ) }}
                    </div>

                </div>


                <div
                    class="
                        border-t border-slate-800
                        p-5 md:border-t-0
                    "
                >

                    <div
                        class="
                            text-xs font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Status
                    </div>

                    <div
                        class="mt-1 text-xl font-black text-white"
                    >
                        {{ $this->statusLabel($market) }}
                    </div>

                </div>

            </div>

        </section>


        {{-- =====================================================
             RESOLVING NOTICE
        ====================================================== --}}
        @if ($market->status === 'resolving')

            <section
                class="
                    mt-6 rounded-2xl
                    border border-amber-400/20
                    bg-amber-400/10
                    px-5 py-5
                "
            >

                <div class="font-bold text-amber-300">
                    Betting is closed
                </div>

                <p
                    class="
                        mt-1 text-sm leading-6
                        text-amber-100/70
                    "
                >
                    The betting period has ended.
                    Please wait for the final result.
                </p>

            </section>

        @endif


        {{-- =====================================================
             RESOLVED NOTICE
        ====================================================== --}}
        @if ($market->status === 'resolved')

            <section
                class="
                    mt-6 rounded-2xl
                    border border-violet-400/20
                    bg-violet-400/10
                    px-5 py-5
                "
            >

                <div class="font-bold text-violet-200">
                    Market resolved
                </div>


                @if ($profitPerDollar !== null)

                    <p
                        class="
                            mt-1 text-sm leading-6
                            text-violet-100/70
                        "
                    >
                        Winning bets earned

                        <span
                            class="
                                font-black
                                text-emerald-300
                            "
                        >
                            +${{ number_format(
                                $profitPerDollar,
                                2
                            ) }}
                        </span>

                        profit for every $1 staked.
                    </p>

                @else

                    <p
                        class="
                            mt-1 text-sm leading-6
                            text-violet-100/70
                        "
                    >
                        The winning outcome is shown below.
                    </p>

                @endif

            </section>

        @endif


        {{-- =====================================================
             MAIN CONTENT
        ====================================================== --}}
        <div
            class="
                mt-6 grid grid-cols-1
                items-start gap-6
                lg:grid-cols-[minmax(0,1fr)_380px]
            "
        >

            {{-- =================================================
                 OUTCOMES
            ================================================== --}}
            <section
                class="
                    rounded-2xl
                    border border-slate-800
                    bg-[#0a1929]
                    p-5 md:p-6
                "
            >

                <div class="mb-5">

                    <div
                        class="
                            text-xs font-bold
                            uppercase tracking-[0.18em]
                            text-blue-400
                        "
                    >
                        Outcomes
                    </div>


                    <h2
                        class="mt-1 text-2xl font-black"
                    >
                        Choose an outcome
                    </h2>


                    @if ($acceptsBets)

                        <p
                            class="
                                mt-2 text-sm
                                text-slate-400
                            "
                        >
                            Select an outcome below to place
                            a virtual bet.
                        </p>

                    @elseif ($market->status === 'resolving')

                        <p
                            class="
                                mt-2 text-sm
                                text-slate-400
                            "
                        >
                            Betting is closed while the
                            result is being determined.
                        </p>

                    @else

                        <p
                            class="
                                mt-2 text-sm
                                text-slate-400
                            "
                        >
                            Final market results.
                        </p>

                    @endif

                </div>


                <div class="space-y-3">

                    @foreach ($market->outcomes as $outcome)

                        @php
                            $outcomeIndex =
                                $loop->index;

                            $percent =
                                $this->outcomePercent(
                                    $market,
                                    $outcome
                                );

                            $outcomePool =
                                $this->outcomePool(
                                    $outcome
                                );

                            $colorIndex =
                                $loop->index % 5;

                            $barClass = match ($colorIndex) {
                                0 => 'bg-emerald-400',
                                1 => 'bg-blue-400',
                                2 => 'bg-violet-400',
                                3 => 'bg-rose-400',
                                default => 'bg-slate-400',
                            };

                            $percentClass = match ($colorIndex) {
                                0 => 'text-emerald-400',
                                1 => 'text-blue-400',
                                2 => 'text-violet-400',
                                3 => 'text-rose-400',
                                default => 'text-slate-300',
                            };
                        @endphp


                        <button
                            type="button"

                            wire:key="market-outcome-{{ $outcomeIndex }}"

                            @if ($acceptsBets)
                                wire:click="openBetModal({{ $outcomeIndex }})"
                            @else
                                disabled
                            @endif

                            class="
                                relative block w-full
                                overflow-hidden
                                rounded-2xl border
                                text-left
                                transition duration-150

                                {{ $outcome->is_winner
                                    ? 'border-emerald-400/80 bg-emerald-500/10'
                                    : 'border-slate-700/70 bg-slate-800/45'
                                }}

                                {{ $acceptsBets
                                    ? 'cursor-pointer hover:border-blue-400/70 hover:bg-slate-800/80 active:scale-[0.995]'
                                    : 'cursor-default'
                                }}
                            "
                        >

                            <div
                                class="
                                    absolute inset-y-0 left-0
                                    opacity-[0.08]
                                    {{ $barClass }}
                                "
                                style="
                                    width:
                                    {{ min(
                                        100,
                                        max(0, $percent)
                                    ) }}%
                                "
                            ></div>


                            <div
                                class="
                                    relative grid
                                    grid-cols-[minmax(0,1fr)_80px]
                                    items-center gap-4
                                    px-4 py-5
                                    sm:grid-cols-[minmax(0,1fr)_90px_120px]
                                "
                            >

                                <div class="min-w-0">

                                    <div
                                        class="
                                            flex items-center
                                            gap-2
                                        "
                                    >

                                        <span
                                            class="
                                                truncate
                                                text-base font-bold
                                                text-slate-100
                                            "
                                            title="{{ $this->outcomeTitle($outcome) }}"
                                        >
                                            {{ $this->outcomeTitle($outcome) }}
                                        </span>


                                        @if ($outcome->is_winner)

                                            <span
                                                class="
                                                    shrink-0
                                                    rounded-full
                                                    bg-emerald-400
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-black
                                                    text-emerald-950
                                                "
                                            >
                                                WINNER
                                            </span>

                                        @endif

                                    </div>


                                    <div
                                        class="
                                            mt-3 h-2
                                            overflow-hidden
                                            rounded-full
                                            bg-slate-700/80
                                        "
                                    >

                                        <div
                                            class="
                                                h-full rounded-full
                                                {{ $barClass }}
                                            "
                                            style="
                                                width:
                                                {{ min(
                                                    100,
                                                    max(
                                                        0,
                                                        $percent
                                                    )
                                                ) }}%
                                            "
                                        ></div>

                                    </div>

                                </div>


                                <div class="text-right">

                                    <span
                                        class="
                                            text-lg font-black
                                            {{ $percentClass }}
                                        "
                                    >
                                        {{ number_format(
                                            $percent,
                                            1
                                        ) }}%
                                    </span>

                                </div>


                                <div
                                    class="
                                        hidden text-right
                                        sm:block
                                    "
                                >

                                    <div
                                        class="
                                            text-sm font-bold
                                            text-slate-200
                                        "
                                    >
                                        ${{ number_format(
                                            $outcomePool,
                                            2
                                        ) }}
                                    </div>

                                    <div
                                        class="
                                            mt-1 text-xs
                                            text-slate-500
                                        "
                                    >
                                        pool
                                    </div>

                                </div>

                            </div>


                            @if ($acceptsBets)

                                <div
                                    class="
                                        relative
                                        border-t
                                        border-slate-700/40
                                        px-4 py-2.5
                                        text-right
                                        text-xs font-semibold
                                        text-blue-400
                                    "
                                >
                                    Click to bet →
                                </div>

                            @elseif (
                                $market->status === 'resolved'
                                && $outcome->is_winner
                                && $profitPerDollar !== null
                            )

                                <div
                                    class="
                                        relative
                                        border-t
                                        border-emerald-500/20
                                        px-4 py-2.5
                                        text-right
                                        text-xs font-bold
                                        text-emerald-300
                                    "
                                >
                                    +${{ number_format(
                                        $profitPerDollar,
                                        2
                                    ) }}
                                    profit per $1
                                </div>

                            @endif

                        </button>

                    @endforeach

                </div>

            </section>


            {{-- =================================================
                 RIGHT COLUMN
            ================================================== --}}
            <aside class="space-y-6">


                {{-- =============================================
                     MARKET DETAILS
                ============================================== --}}
                <section
                    class="
                        rounded-2xl
                        border border-slate-800
                        bg-[#0a1929]
                        p-5
                    "
                >

                    <h2 class="text-lg font-black">
                        Market details
                    </h2>


                    <div
                        class="mt-5 space-y-4 text-sm"
                    >

                        <div
                            class="
                                flex items-start
                                justify-between gap-4
                                border-b
                                border-slate-800
                                pb-4
                            "
                        >

                            <span class="text-slate-500">
                                Category
                            </span>

                            <span
                                class="
                                    text-right
                                    font-semibold
                                    text-slate-200
                                "
                            >
                                {{ $this->categoryName($market) }}
                            </span>

                        </div>


                        <div
                            class="
                                flex items-start
                                justify-between gap-4
                                border-b
                                border-slate-800
                                pb-4
                            "
                        >

                            <span class="text-slate-500">
                                Deadline
                            </span>

                            <span
                                class="
                                    text-right
                                    font-semibold
                                    text-slate-200
                                "
                            >

                                @if ($market->finish_at)

                                    {{ $market->finish_at->format(
                                        'M j, Y H:i'
                                    ) }}

                                @else

                                    —

                                @endif

                            </span>

                        </div>


                        @if ($market->countries->isNotEmpty())

                            <div
                                class="
                                    flex items-start
                                    justify-between gap-4
                                    border-b
                                    border-slate-800
                                    pb-4
                                "
                            >

                                <span class="text-slate-500">
                                    Countries
                                </span>


                                <div
                                    class="
                                        flex max-w-[220px]
                                        flex-wrap
                                        justify-end
                                        gap-1.5
                                    "
                                >

                                    @foreach ($market->countries as $country)

                                        <span
                                            class="
                                                rounded-lg
                                                border
                                                border-slate-700
                                                bg-slate-800
                                                px-2 py-1
                                                text-xs font-semibold
                                                text-slate-300
                                            "
                                        >
                                            {{ $country->code }}
                                        </span>

                                    @endforeach

                                </div>

                            </div>

                        @endif


                        @if ($market->published_at)

                            <div
                                class="
                                    flex items-start
                                    justify-between gap-4
                                "
                            >

                                <span class="text-slate-500">
                                    Published
                                </span>

                                <span
                                    class="
                                        text-right
                                        font-semibold
                                        text-slate-200
                                    "
                                >
                                    {{ $market->published_at->format(
                                        'M j, Y'
                                    ) }}
                                </span>

                            </div>

                        @endif

                    </div>

                </section>


                {{-- =============================================
                     YOUR BETS
                ============================================== --}}
                <section
                    class="
                        rounded-2xl
                        border border-slate-800
                        bg-[#0a1929]
                        p-5
                    "
                >

                    <div
                        class="
                            flex items-center
                            justify-between gap-4
                        "
                    >

                        <h2 class="text-lg font-black">
                            Your bets
                        </h2>


                        @if ($this->myBets->isNotEmpty())

                            <span
                                class="
                                    rounded-full
                                    bg-blue-500/10
                                    px-2.5 py-1
                                    text-xs font-bold
                                    text-blue-300
                                "
                            >
                                {{ $this->myBets->count() }}
                            </span>

                        @endif

                    </div>


                    @if ($this->myBets->isEmpty())

                        <div
                            class="
                                mt-4 rounded-xl
                                border border-slate-800
                                bg-slate-900/40
                                px-4 py-5
                                text-sm leading-6
                                text-slate-500
                            "
                        >
                            You have not placed a bet on
                            this market yet.
                        </div>

                    @else

                        <div class="mt-4 space-y-3">

                            @foreach ($this->myBets as $bet)

                                @php
                                    $betStatusClass = match ($bet->status) {
                                        'won' =>
                                            'border-emerald-400/20 bg-emerald-400/10 text-emerald-300',

                                        'lost' =>
                                            'border-rose-400/20 bg-rose-400/10 text-rose-300',

                                        'refunded' =>
                                            'border-blue-400/20 bg-blue-400/10 text-blue-300',

                                        default =>
                                            'border-slate-700 bg-slate-800 text-slate-300',
                                    };
                                @endphp


                                <div
                                    wire:key="my-bet-{{ $bet->id }}"
                                    class="
                                        rounded-xl
                                        border border-slate-800
                                        bg-slate-900/40
                                        p-4
                                    "
                                >

                                    <div
                                        class="
                                            flex items-start
                                            justify-between gap-3
                                        "
                                    >

                                        <div class="min-w-0">

                                            <div
                                                class="
                                                    truncate
                                                    text-sm font-bold
                                                    text-slate-100
                                                "
                                            >
                                                {{ $this->outcomeTitle(
                                                    $bet->outcome
                                                ) }}
                                            </div>

                                            <div
                                                class="
                                                    mt-1 text-xs
                                                    text-slate-500
                                                "
                                            >
                                                Stake
                                                ${{ number_format(
                                                    (float) $bet->amount,
                                                    2
                                                ) }}
                                            </div>

                                        </div>


                                        <span
                                            class="
                                                shrink-0
                                                rounded-full
                                                border
                                                px-2 py-1
                                                text-[10px]
                                                font-black
                                                uppercase
                                                {{ $betStatusClass }}
                                            "
                                        >
                                            {{ $bet->status }}
                                        </span>

                                    </div>


                                    @if ($bet->status === 'won')

                                        <div
                                            class="
                                                mt-3
                                                border-t
                                                border-slate-800
                                                pt-3
                                            "
                                        >

                                            <div
                                                class="
                                                    flex items-center
                                                    justify-between
                                                    text-xs
                                                "
                                            >

                                                <span
                                                    class="text-slate-500"
                                                >
                                                    Profit
                                                </span>

                                                <span
                                                    class="
                                                        font-bold
                                                        text-emerald-300
                                                    "
                                                >
                                                    +${{ number_format(
                                                        (float) $bet->profit,
                                                        2
                                                    ) }}
                                                </span>

                                            </div>


                                            <div
                                                class="
                                                    mt-2
                                                    flex items-center
                                                    justify-between
                                                    text-xs
                                                "
                                            >

                                                <span
                                                    class="text-slate-500"
                                                >
                                                    Total payout
                                                </span>

                                                <span
                                                    class="
                                                        font-black
                                                        text-white
                                                    "
                                                >
                                                    ${{ number_format(
                                                        (float) $bet->payout,
                                                        2
                                                    ) }}
                                                </span>

                                            </div>

                                        </div>

                                    @elseif ($bet->status === 'lost')

                                        <div
                                            class="
                                                mt-3
                                                border-t
                                                border-slate-800
                                                pt-3
                                                text-xs
                                                text-rose-300
                                            "
                                        >
                                            This bet did not win.
                                        </div>

                                    @elseif ($bet->status === 'refunded')

                                        <div
                                            class="
                                                mt-3
                                                border-t
                                                border-slate-800
                                                pt-3
                                            "
                                        >

                                            <div
                                                class="
                                                    flex items-center
                                                    justify-between
                                                    text-xs
                                                "
                                            >

                                                <span
                                                    class="text-slate-500"
                                                >
                                                    Returned
                                                </span>

                                                <span
                                                    class="
                                                        font-bold
                                                        text-blue-300
                                                    "
                                                >
                                                    ${{ number_format(
                                                        (float) $bet->payout,
                                                        2
                                                    ) }}
                                                </span>

                                            </div>

                                        </div>

                                    @endif

                                </div>

                            @endforeach

                        </div>

                    @endif

                </section>

            </aside>

        </div>


        <footer
            class="
                py-10 text-center
                text-xs text-slate-600
            "
        >
            Virtual prediction market
        </footer>

    </main>


    {{-- =========================================================
         BET MODAL
    ========================================================== --}}
    @if ($betModalOpen)

        <div
            class="
                fixed inset-0 z-[100]
                flex items-center justify-center
                p-4
            "
            role="dialog"
            aria-modal="true"
        >

            {{-- BACKDROP --}}
            <button
                type="button"
                wire:click="closeBetModal"
                class="
                    absolute inset-0
                    h-full w-full
                    cursor-default
                    bg-black/75
                    backdrop-blur-sm
                "
                aria-label="Close bet dialog"
            ></button>


            {{-- MODAL --}}
            <div
                class="
                    relative z-10
                    w-full max-w-lg
                    overflow-hidden
                    rounded-3xl
                    border border-slate-700
                    bg-[#0a1929]
                    shadow-2xl shadow-black/50
                "
            >

                <div
                    class="
                        border-b border-slate-800
                        bg-gradient-to-br
                        from-[#102b4b]
                        to-[#071422]
                        px-6 py-5
                    "
                >

                    <div
                        class="
                            flex items-start
                            justify-between gap-5
                        "
                    >

                        <div>

                            <div
                                class="
                                    text-xs font-bold
                                    uppercase
                                    tracking-[0.18em]
                                    text-blue-400
                                "
                            >
                                Place virtual bet
                            </div>

                            <h3
                                class="
                                    mt-2 text-xl
                                    font-black text-white
                                "
                            >
                                You are placing a bet
                            </h3>

                        </div>


                        <button
                            type="button"
                            wire:click="closeBetModal"
                            class="
                                flex h-9 w-9
                                shrink-0
                                items-center
                                justify-center
                                rounded-full
                                border border-slate-700
                                bg-slate-900/70
                                text-xl text-slate-400
                                transition
                                hover:border-slate-500
                                hover:text-white
                            "
                            aria-label="Close"
                        >
                            ×
                        </button>

                    </div>

                </div>


                <div class="p-6">

                    <div
                        class="
                            text-xs font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Market
                    </div>


                    <div
                        class="
                            mt-1 text-base
                            font-bold leading-6
                            text-white
                        "
                    >
                        {{ $betMarketTitle }}
                    </div>


                    <div
                        class="
                            mt-5 text-xs
                            font-semibold
                            uppercase tracking-wider
                            text-slate-500
                        "
                    >
                        Selected outcome
                    </div>


                    <div
                        class="
                            mt-2 rounded-xl
                            border border-blue-500/30
                            bg-blue-500/10
                            px-4 py-3
                            text-sm font-bold
                            text-blue-200
                        "
                    >
                        {{ $betOutcomeTitle }}
                    </div>


                    <div class="mt-6">

                        <label
                            for="market-bet-amount"
                            class="
                                text-sm font-semibold
                                text-slate-200
                            "
                        >
                            Bet amount
                        </label>


                        <div class="relative mt-2">

                            <div
                                class="
                                    pointer-events-none
                                    absolute inset-y-0 left-0
                                    flex items-center
                                    pl-4
                                    text-lg font-bold
                                    text-slate-500
                                "
                            >
                                $
                            </div>


                            <input
                                id="market-bet-amount"
                                type="number"
                                min="0.01"
                                max="10000"
                                step="0.01"
                                inputmode="decimal"
                                wire:model="betAmount"
                                wire:keydown.enter="placeBet"
                                autofocus
                                placeholder="0.00"
                                class="
                                    w-full rounded-xl
                                    border border-slate-700
                                    bg-slate-950/70
                                    py-4 pl-9 pr-4
                                    text-lg font-bold
                                    text-white
                                    outline-none
                                    transition
                                    placeholder:text-slate-600
                                    focus:border-blue-500
                                    focus:ring-2
                                    focus:ring-blue-500/20
                                "
                            >

                        </div>


                        <div
                            class="
                                mt-2 flex
                                items-center
                                justify-between
                                gap-3
                            "
                        >

                            <div
                                class="text-xs text-slate-500"
                            >
                                Minimum $0.01
                            </div>

                            <div
                                class="text-xs text-slate-500"
                            >
                                Maximum $10,000.00
                            </div>

                        </div>


                        @error('betAmount')

                        <div
                            class="
                                    mt-3 rounded-lg
                                    border border-rose-500/20
                                    bg-rose-500/10
                                    px-3 py-2
                                    text-sm text-rose-300
                                "
                        >
                            {{ $message }}
                        </div>

                        @enderror

                    </div>


                    <div
                        class="
                            mt-7 flex
                            items-center
                            justify-end
                            gap-3
                        "
                    >

                        <button
                            type="button"
                            wire:click="closeBetModal"
                            wire:loading.attr="disabled"
                            wire:target="placeBet"
                            class="
                                rounded-xl
                                border border-slate-700
                                bg-slate-800
                                px-5 py-3
                                text-sm font-semibold
                                text-slate-300
                                transition
                                hover:border-slate-600
                                hover:bg-slate-700
                                hover:text-white
                                disabled:opacity-50
                            "
                        >
                            Cancel
                        </button>


                        <button
                            type="button"
                            wire:click="placeBet"
                            wire:loading.attr="disabled"
                            wire:target="placeBet"
                            class="
                                min-w-32 rounded-xl
                                bg-blue-600
                                px-5 py-3
                                text-sm font-bold
                                text-white
                                shadow-lg
                                shadow-blue-600/20
                                transition
                                hover:bg-blue-500
                                disabled:cursor-wait
                                disabled:opacity-60
                            "
                        >

                            <span
                                wire:loading.remove
                                wire:target="placeBet"
                            >
                                Place bet
                            </span>

                            <span
                                wire:loading
                                wire:target="placeBet"
                            >
                                Placing...
                            </span>

                        </button>

                    </div>

                </div>

            </div>

        </div>

    @endif

</div>
