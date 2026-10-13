<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\Market;
use App\Services\Betting\VirtualBetService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    /**
     * Hard server-side limit.
     */
    private const PAGE_SIZE = 12;

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

    public string $category = '';

    public string $country = '';

    public string $sort = 'newest';

    public string $locale = 'en';

    /*
     * Bet modal state.
     *
     * We deliberately do not expose the internal outcome ID.
     * The selected outcome is identified by its position inside
     * the server-side ordered outcome collection.
     */
    public bool $betModalOpen = false;

    public ?string $betMarketPublicId = null;

    public ?int $betOutcomeIndex = null;

    public string $betMarketTitle = '';

    public string $betOutcomeTitle = '';

    public string $betAmount = '';

    public ?string $betSuccessMessage = null;

    /**
     * Categories that currently have visible markets.
     */
    public function getCategoriesProperty(): Collection
    {
        return Category::query()
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->orderBy('name')
            ->get([
                'categories.id',
                'categories.name',
                'categories.slug',
            ]);
    }

    /**
     * Countries that currently have visible markets.
     */
    public function getCountriesProperty(): Collection
    {
        return Country::query()
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->orderBy('name')
            ->get([
                'countries.id',
                'countries.code',
                'countries.name',
            ]);
    }

    /**
     * Visible markets.
     */
    public function getMarketsProperty(): Collection
    {
        $category = $this->validCategory();
        $country = $this->validCountry();
        $sort = $this->validSort();
        $locale = $this->validLocale();

        return Market::query()
            ->whereIn(
                'status',
                self::MARKET_STATUSES
            )

            ->when(
                $category !== '',
                function ($query) use ($category) {
                    $query->whereHas(
                        'categories',
                        function ($categoryQuery) use ($category) {
                            $categoryQuery->where(
                                'categories.slug',
                                $category
                            );
                        }
                    );
                }
            )

            ->when(
                $country !== '',
                function ($query) use ($country) {
                    $query->whereHas(
                        'countries',
                        function ($countryQuery) use ($country) {
                            $countryQuery->where(
                                'countries.code',
                                $country
                            );
                        }
                    );
                }
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

                'categories',
            ])

            ->withCount([
                'virtualBets as bets_count',

                'virtualBets as traders_count' => function ($query) {
                    $query->selectRaw(
                        'COUNT(DISTINCT visitor_id)'
                    );
                },
            ])

            ->when(
                $sort === 'newest',
                fn ($query) => $query
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
            )

            ->when(
                $sort === 'ending',
                fn ($query) => $query
                    ->orderBy('finish_at')
                    ->orderByDesc('published_at')
            )

            ->limit(self::PAGE_SIZE)
            ->get();
    }

    private function validCategory(): string
    {
        $slug = trim($this->category);

        if ($slug === '') {
            return '';
        }

        $exists = Category::query()
            ->where('slug', $slug)
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->exists();

        return $exists
            ? $slug
            : '';
    }

    private function validCountry(): string
    {
        $code = strtoupper(
            trim($this->country)
        );

        if ($code === '') {
            return '';
        }

        $exists = Country::query()
            ->where('code', $code)
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->exists();

        return $exists
            ? $code
            : '';
    }

    private function validSort(): string
    {
        return in_array(
            $this->sort,
            [
                'newest',
                'ending',
            ],
            true
        )
            ? $this->sort
            : 'newest';
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

    public function selectCategory(string $slug = ''): void
    {
        $slug = trim($slug);

        if ($slug === '') {
            $this->category = '';

            return;
        }

        $exists = Category::query()
            ->where('slug', $slug)
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->exists();

        $this->category = $exists
            ? $slug
            : '';
    }

    public function selectCountry(string $code = ''): void
    {
        $code = strtoupper(
            trim($code)
        );

        if ($code === '') {
            $this->country = '';

            return;
        }

        $exists = Country::query()
            ->where('code', $code)
            ->whereHas('markets', function ($query) {
                $query->whereIn(
                    'markets.status',
                    self::MARKET_STATUSES
                );
            })
            ->exists();

        $this->country = $exists
            ? $code
            : '';
    }

    public function clearFilters(): void
    {
        $this->category = '';
        $this->country = '';
    }

    /**
     * Open the virtual bet modal.
     *
     * The market and outcome are always reloaded from the database.
     */
    public function openBetModal(
        string $marketPublicId,
        int $outcomeIndex
    ): void {
        $this->resetValidation();
        $this->betSuccessMessage = null;

        $market = Market::query()
            ->where('public_id', $marketPublicId)
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

        if (! $this->marketAcceptsBets($market)) {
            return;
        }

        $outcome = $market->outcomes
            ->values()
            ->get($outcomeIndex);

        if (! $outcome) {
            return;
        }

        $this->betMarketPublicId =
            $market->public_id;

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

        $this->betMarketPublicId = null;
        $this->betOutcomeIndex = null;

        $this->betMarketTitle = '';
        $this->betOutcomeTitle = '';
        $this->betAmount = '';

        $this->resetValidation();
    }

    /**
     * Validate the Livewire form and delegate the actual
     * bet creation to the shared betting service.
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
            ! $this->betMarketPublicId
            || $this->betOutcomeIndex === null
        ) {
            throw ValidationException::withMessages([
                'betAmount' =>
                    'The selected market is no longer available.',
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
            $this->betMarketPublicId,

            outcomeIndex:
            $this->betOutcomeIndex,

            visitorId:
            $this->visitorId(),

            amount:
            $amount,
        );

        $this->betModalOpen = false;

        $this->betMarketPublicId = null;
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
     *
     * No User model or registration is required.
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

    /**
     * UI-only check.
     *
     * VirtualBetService performs the authoritative check
     * again while the market row is locked.
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

    public function marketTitle(
        Market $market
    ): string {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere('locale', $locale)
            ?->title
            ?? $market->translations
            ->firstWhere('locale', 'en')
            ?->title
            ?? 'Untitled market';
    }

    public function marketDescription(
        Market $market
    ): ?string {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere('locale', $locale)
            ?->description
            ?? $market->translations
                ->firstWhere('locale', 'en')
                ?->description;
    }

    public function outcomeTitle(
        $outcome
    ): string {
        $locale = $this->validLocale();

        return $outcome->translations
            ->firstWhere('locale', $locale)
            ?->title
            ?? $outcome->translations
            ->firstWhere('locale', 'en')
            ?->title
            ?? 'Outcome';
    }

    public function marketPool(
        Market $market
    ): float {
        return (float) $market->outcomes->sum(
            fn ($outcome) =>
            (float) ($outcome->bet_pool ?? 0)
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

    public function traderCount(
        Market $market
    ): int {
        return (int) (
            $market->traders_count ?? 0
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
     * Profit for each $1 staked on the winning outcome.
     *
     * Example:
     * winning pool = $6,300
     * losing pool  = $3,700
     *
     * profit_per_dollar = 3700 / 6300
     */
    public function resolvedProfitPerDollar(
        Market $market
    ): ?float {
        if ($market->status !== 'resolved') {
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

    public function timeLeft(
        Market $market
    ): string {
        if (! $market->finish_at) {
            return 'No deadline';
        }

        if ($market->status === 'resolved') {
            return 'Finished';
        }

        if ($market->status === 'resolving') {
            return 'Betting closed';
        }

        if ($market->finish_at->isPast()) {
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
            return $days.'d '.$hours.'h left';
        }

        if ($hours > 0) {
            return $hours.'h '.$minutes.'m left';
        }

        return max(
                1,
                $minutes
            ).'m left';
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
};
?>

<div class="min-h-screen bg-[#06111f] text-white">

    {{-- =========================================================
         HEADER
    ========================================================== --}}
    <header class="border-b border-slate-800/80 bg-[#071321]/95">
        <div class="mx-auto flex max-w-[1500px] items-center gap-8 px-5 py-4 lg:px-8">

            <a href="/" class="flex shrink-0 items-center gap-3">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-xl font-black shadow-lg shadow-blue-600/20"
                >
                    W
                </div>

                <div class="text-xl font-black tracking-wide">
                    WRANGLE
                </div>
            </a>

            <nav class="hidden items-center gap-8 text-sm text-slate-300 md:flex">
                <a
                    href="/"
                    class="border-b-2 border-blue-500 py-5 font-semibold text-white"
                >
                    Markets
                </a>

                <span class="cursor-default text-slate-500">
                    Categories
                </span>

                <span class="cursor-default text-slate-500">
                    How it works
                </span>

                <span class="cursor-default text-slate-500">
                    About
                </span>
            </nav>

            <div class="ml-auto hidden w-full max-w-sm lg:block">
                <div
                    class="flex items-center gap-3 rounded-xl border border-slate-700/80 bg-slate-900/60 px-4 py-3 text-sm text-slate-500"
                >
                    <span class="text-lg">
                        ⌕
                    </span>

                    <span>
                        Search markets...
                    </span>
                </div>
            </div>

            <select
                wire:model.live="locale"
                wire:loading.attr="disabled"
                class="
                    rounded-xl border border-slate-800
                    bg-slate-900 px-3 py-3
                    text-sm text-slate-200
                    outline-none transition
                    hover:border-slate-700
                    focus:border-blue-500
                "
                aria-label="Language"
            >
                <option value="en">EN</option>
                <option value="es">ES</option>
            </select>

            <div
                class="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3 text-slate-400"
            >
                ◐
            </div>

        </div>
    </header>


    <main class="mx-auto max-w-[1500px] px-5 py-8 lg:px-8">

        {{-- =====================================================
             HERO
        ====================================================== --}}
        <section
            class="relative mb-8 overflow-hidden rounded-3xl border border-blue-900/30 bg-gradient-to-br from-[#0a1b31] via-[#071727] to-[#06111f] px-7 py-10 lg:px-10"
        >
            <div
                class="pointer-events-none absolute -right-32 -top-48 h-[500px] w-[500px] rounded-full bg-blue-600/10 blur-3xl"
            ></div>

            <div
                class="pointer-events-none absolute -bottom-32 left-1/3 h-[350px] w-[350px] rounded-full bg-cyan-500/5 blur-3xl"
            ></div>

            <div class="relative max-w-3xl">
                <p
                    class="mb-2 text-sm font-semibold uppercase tracking-[0.22em] text-blue-400"
                >
                    Prediction markets
                </p>

                <h1 class="text-4xl font-black tracking-tight md:text-5xl">
                    Predict the future.
                </h1>

                <h2
                    class="mt-2 text-3xl font-black tracking-tight text-blue-500 md:text-4xl"
                >
                    Live markets. Real outcomes.
                </h2>

                <p
                    class="mt-5 max-w-2xl text-base leading-7 text-slate-400"
                >
                    Explore real-world prediction markets,
                    place virtual bets and see how accurate
                    your predictions are.
                </p>
            </div>
        </section>


        {{-- =====================================================
             SUCCESS MESSAGE
        ====================================================== --}}
        @if ($betSuccessMessage)

            <div
                class="mb-6 flex items-start justify-between gap-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-200"
            >
                <div>
                    <span class="mr-2 font-black text-emerald-400">
                        ✓
                    </span>

                    {{ $betSuccessMessage }}
                </div>

                <button
                    type="button"
                    wire:click="$set('betSuccessMessage', null)"
                    class="text-lg leading-none text-emerald-300 transition hover:text-white"
                >
                    ×
                </button>
            </div>

        @endif


        {{-- =====================================================
             FILTERS
        ====================================================== --}}
        <section
            class="mb-7 rounded-2xl border border-slate-800 bg-slate-900/30 p-4"
        >
            <div class="flex flex-wrap items-center gap-3">

                <button
                    type="button"
                    wire:click="selectCategory('')"
                    wire:loading.attr="disabled"
                    class="
                        rounded-xl px-5 py-3 text-sm font-semibold
                        transition duration-150
                        disabled:cursor-wait disabled:opacity-60

                        {{ $category === ''
                            ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20'
                            : 'border border-slate-800 bg-slate-900/50 text-slate-300 hover:border-slate-600 hover:bg-slate-800/70 hover:text-white'
                        }}
                    "
                >
                    All Markets
                </button>


                @foreach ($this->categories as $categoryItem)

                    <button
                        type="button"
                        wire:key="category-{{ $categoryItem->slug }}"
                        wire:click="selectCategory(@js($categoryItem->slug))"
                        wire:loading.attr="disabled"
                        class="
                            rounded-xl px-5 py-3 text-sm font-semibold
                            transition duration-150
                            disabled:cursor-wait disabled:opacity-60

                            {{ $category === $categoryItem->slug
                                ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/20'
                                : 'border border-slate-800 bg-slate-900/50 text-slate-300 hover:border-slate-600 hover:bg-slate-800/70 hover:text-white'
                            }}
                        "
                    >
                        {{ $categoryItem->name }}
                    </button>

                @endforeach

            </div>


            <div
                class="mt-4 flex flex-col gap-3 border-t border-slate-800 pt-4 sm:flex-row sm:items-center"
            >

                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Country
                    </span>

                    <select
                        wire:model.live="country"
                        wire:loading.attr="disabled"
                        class="
                            min-w-44 rounded-xl
                            border border-slate-800
                            bg-slate-900 px-4 py-3
                            text-sm text-slate-200
                            outline-none transition
                            hover:border-slate-700
                            focus:border-blue-500
                        "
                    >
                        <option value="">
                            All countries
                        </option>

                        @foreach ($this->countries as $countryItem)

                            <option
                                wire:key="country-{{ $countryItem->code }}"
                                value="{{ $countryItem->code }}"
                            >
                                {{ $countryItem->name }}
                            </option>

                        @endforeach
                    </select>
                </div>


                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Language
                    </span>

                    <select
                        wire:model.live="locale"
                        wire:loading.attr="disabled"
                        class="
                            min-w-36 rounded-xl
                            border border-slate-800
                            bg-slate-900 px-4 py-3
                            text-sm text-slate-200
                            outline-none transition
                            hover:border-slate-700
                            focus:border-blue-500
                        "
                    >
                        <option value="en">English</option>
                        <option value="es">Español</option>
                    </select>
                </div>


                <div class="flex items-center gap-2 sm:ml-auto">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Sort
                    </span>

                    <select
                        wire:model.live="sort"
                        wire:loading.attr="disabled"
                        class="
                            min-w-40 rounded-xl
                            border border-slate-800
                            bg-slate-900 px-4 py-3
                            text-sm text-slate-200
                            outline-none transition
                            hover:border-slate-700
                            focus:border-blue-500
                        "
                    >
                        <option value="newest">
                            Newest first
                        </option>

                        <option value="ending">
                            Ending soon
                        </option>
                    </select>
                </div>

            </div>
        </section>


        {{-- =====================================================
             LOADING
        ====================================================== --}}
        <div
            wire:loading.flex
            wire:target="selectCategory,country,locale,sort,clearFilters"
            class="mb-5 items-center gap-3 rounded-xl border border-blue-900/30 bg-blue-950/20 px-4 py-3 text-sm text-blue-300"
        >
            <div
                class="h-4 w-4 animate-spin rounded-full border-2 border-blue-400 border-t-transparent"
            ></div>

            Updating markets...
        </div>


        {{-- =====================================================
             MARKET GRID
        ====================================================== --}}
        <section
            wire:loading.class="opacity-50"
            wire:target="selectCategory,country,locale,sort,clearFilters"
            class="grid grid-cols-1 items-start gap-5 transition-opacity md:grid-cols-2 xl:grid-cols-3"
        >

            @forelse ($this->markets as $market)

                @php
                    $pool = $this->marketPool($market);

                    $acceptsBets =
                        $this->marketAcceptsBets($market);

                    $profitPerDollar =
                        $this->resolvedProfitPerDollar($market);

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


                <article
                    wire:key="market-{{ $market->public_id }}"
                    class="
                        overflow-hidden rounded-2xl
                        border border-slate-800
                        bg-[#0a1929]
                        shadow-xl shadow-black/10
                        transition duration-200
                        hover:-translate-y-0.5
                        hover:border-slate-700
                        hover:shadow-2xl
                        hover:shadow-black/20
                    "
                >

                    {{-- CARD IMAGE --}}
                    <div
                        class="relative min-h-44 overflow-hidden border-b border-slate-800 bg-gradient-to-br from-blue-950 via-[#102b4b] to-[#071422] p-4"
                    >

                        @if ($market->image_url)

                            <img
                                src="{{ $market->image_url }}"
                                alt=""
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                class="absolute inset-0 h-full w-full object-cover"
                                onerror="this.style.display='none'"
                            >

                            <div
                                class="absolute inset-0 bg-gradient-to-t from-[#06111f]/90 via-[#06111f]/25 to-black/30"
                            ></div>

                            <div
                                class="absolute inset-0 bg-gradient-to-r from-black/20 via-transparent to-black/10"
                            ></div>

                        @else

                            <div
                                class="absolute -right-12 -top-16 h-44 w-44 rounded-full bg-blue-500/20 blur-3xl"
                            ></div>

                            <div
                                class="absolute bottom-0 left-1/3 h-24 w-40 rounded-full bg-cyan-400/5 blur-2xl"
                            ></div>

                        @endif


                        <div class="relative z-10 flex items-start justify-between gap-3">

                            <span
                                class="rounded-full border border-blue-400/40 bg-[#06111f]/75 px-3 py-1.5 text-xs font-medium text-slate-100 shadow-sm backdrop-blur-md"
                            >
                                {{ $this->categoryName($market) }}
                            </span>

                            <span
                                class="rounded-full px-3 py-1.5 text-xs font-bold shadow-sm {{ $statusClass }}"
                            >
                                {{ $this->statusLabel($market) }}
                            </span>

                        </div>


                        <div class="absolute bottom-4 right-4 z-10">
                            <span
                                class="rounded-lg border border-white/10 bg-black/55 px-3 py-1.5 text-xs font-medium text-slate-100 shadow-sm backdrop-blur-md"
                            >
                                ◷ {{ $this->timeLeft($market) }}
                            </span>
                        </div>

                    </div>


                    {{-- CARD CONTENT --}}
                    <div class="p-4">

                        <h2 class="min-h-14 text-lg font-bold leading-6 text-white">
                            {{ $this->marketTitle($market) }}
                        </h2>


                        @if ($this->marketDescription($market))

                            <p class="mt-2 line-clamp-2 text-sm leading-5 text-slate-400">
                                {{ $this->marketDescription($market) }}
                            </p>

                        @endif


                        {{-- RESOLVING MESSAGE --}}
                        @if ($market->status === 'resolving')

                            <div
                                class="mt-4 rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3"
                            >
                                <div class="text-sm font-bold text-amber-300">
                                    Betting is closed
                                </div>

                                <div class="mt-1 text-xs leading-5 text-amber-100/70">
                                    The betting period has ended.
                                    Please wait for the result.
                                </div>
                            </div>

                        @endif


                        {{-- RESOLVED RESULT --}}
                        @if ($market->status === 'resolved')

                            <div
                                class="mt-4 rounded-xl border border-violet-400/20 bg-violet-400/10 px-4 py-3"
                            >
                                <div class="text-sm font-bold text-violet-200">
                                    Market resolved
                                </div>

                                @if ($profitPerDollar !== null)

                                    <div class="mt-1 text-xs leading-5 text-violet-100/70">
                                        Winning bets earned

                                        <span class="font-bold text-emerald-300">
                                            +${{ number_format($profitPerDollar, 2) }}
                                        </span>

                                        profit for every $1 staked.
                                    </div>

                                @else

                                    <div class="mt-1 text-xs leading-5 text-violet-100/70">
                                        The winning outcome is shown below.
                                    </div>

                                @endif
                            </div>

                        @endif


                        {{-- OUTCOMES --}}
                        <div class="mt-4 space-y-2">

                            @foreach ($market->outcomes as $outcome)

                                @php
                                    $outcomeIndex = $loop->index;

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
                                    wire:key="outcome-{{ $market->public_id }}-{{ $outcomeIndex }}"

                                    @if ($acceptsBets)
                                        wire:click="openBetModal(@js($market->public_id), {{ $outcomeIndex }})"
                                    @else
                                        disabled
                                    @endif

                                    class="
                                        relative block w-full overflow-hidden
                                        rounded-xl border text-left
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
                                        class="absolute inset-y-0 left-0 opacity-[0.08] {{ $barClass }}"
                                        style="width: {{ min(100, max(0, $percent)) }}%"
                                    ></div>


                                    <div
                                        class="relative grid grid-cols-[minmax(0,1fr)_72px_90px] items-center gap-3 px-3 py-3"
                                    >

                                        <div class="min-w-0">

                                            <div class="flex items-center gap-2">

                                                <span
                                                    class="truncate text-sm font-medium text-slate-100"
                                                    title="{{ $this->outcomeTitle($outcome) }}"
                                                >
                                                    {{ $this->outcomeTitle($outcome) }}
                                                </span>


                                                @if ($outcome->is_winner)

                                                    <span
                                                        class="shrink-0 rounded-full bg-emerald-400 px-2 py-0.5 text-[10px] font-black text-emerald-950"
                                                    >
                                                        WINNER
                                                    </span>

                                                @endif

                                            </div>


                                            <div
                                                class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-700/80"
                                            >
                                                <div
                                                    class="h-full rounded-full {{ $barClass }}"
                                                    style="width: {{ min(100, max(0, $percent)) }}%"
                                                ></div>
                                            </div>

                                        </div>


                                        <div class="text-right">
                                            <span
                                                class="text-base font-bold {{ $percentClass }}"
                                            >
                                                {{ number_format($percent, 1) }}%
                                            </span>
                                        </div>


                                        <div class="text-right text-xs text-slate-400">
                                            ${{ number_format($outcomePool, 2) }}
                                        </div>

                                    </div>


                                    @if ($acceptsBets)

                                        <div
                                            class="relative border-t border-slate-700/40 px-3 py-2 text-right text-[11px] font-semibold text-blue-400"
                                        >
                                            Click to bet →
                                        </div>

                                    @elseif (
                                        $market->status === 'resolved'
                                        && $outcome->is_winner
                                        && $profitPerDollar !== null
                                    )

                                        <div
                                            class="relative border-t border-emerald-500/20 px-3 py-2 text-right text-[11px] font-bold text-emerald-300"
                                        >
                                            +${{ number_format($profitPerDollar, 2) }}
                                            profit per $1
                                        </div>

                                    @endif

                                </button>

                            @endforeach

                        </div>


                        {{-- CARD FOOTER --}}
                        <div
                            class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-slate-800 pt-4"
                        >

                            <div class="text-xs text-slate-400">
                                <span class="font-semibold text-slate-200">
                                    {{ number_format($this->traderCount($market)) }}
                                </span>

                                traders
                            </div>


                            <div class="text-xs text-slate-400">
                                <span class="font-semibold text-slate-200">
                                    ${{ number_format($pool, 2) }}
                                </span>

                                volume
                            </div>


                            <div class="text-xs text-slate-500">
                                {{ number_format($this->betCount($market)) }}
                                bets
                            </div>


                            <a
                                href="{{ route('markets.show', ['publicId' => $market->public_id]) }}"
                                class="
                                            ml-auto rounded-lg
                                            bg-blue-600 px-4 py-2.5
                                            text-sm font-semibold text-white
                                            transition hover:bg-blue-500
                                        "
                            >
                                {{ $market->status === 'resolved'
                                    ? 'View results'
                                    : 'View market'
                                }}

                                →
                            </a>

                        </div>

                    </div>

                </article>


            @empty

                <div
                    class="col-span-full rounded-2xl border border-slate-800 bg-slate-900/40 px-6 py-16 text-center"
                >

                    <div class="text-xl font-bold text-white">
                        No markets available
                    </div>

                    <p class="mt-2 text-sm text-slate-400">

                        @if ($category !== '' && $country !== '')

                            There are currently no markets
                            matching this category and country.

                        @elseif ($category !== '')

                            There are currently no markets
                            in this category.

                        @elseif ($country !== '')

                            There are currently no markets
                            for this country.

                        @else

                            New prediction markets will appear here automatically.

                        @endif

                    </p>


                    @if ($category !== '' || $country !== '')

                        <button
                            type="button"
                            wire:click="clearFilters"
                            wire:loading.attr="disabled"
                            class="
                                mt-5 rounded-xl
                                bg-blue-600 px-5 py-3
                                text-sm font-semibold text-white
                                transition
                                hover:bg-blue-500
                                disabled:cursor-wait
                                disabled:opacity-60
                            "
                        >
                            Clear filters
                        </button>

                    @endif

                </div>

            @endforelse

        </section>


        <footer class="py-10 text-center text-xs text-slate-600">
            Maximum {{ self::PAGE_SIZE }} markets per request
        </footer>

    </main>


    {{-- =========================================================
         BET MODAL
    ========================================================== --}}
    @if ($betModalOpen)

        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >

            {{-- BACKDROP --}}
            <button
                type="button"
                wire:click="closeBetModal"
                class="absolute inset-0 h-full w-full cursor-default bg-black/75 backdrop-blur-sm"
                aria-label="Close bet dialog"
            ></button>


            {{-- MODAL --}}
            <div
                class="relative z-10 w-full max-w-lg overflow-hidden rounded-3xl border border-slate-700 bg-[#0a1929] shadow-2xl shadow-black/50"
            >

                <div
                    class="border-b border-slate-800 bg-gradient-to-br from-[#102b4b] to-[#071422] px-6 py-5"
                >
                    <div class="flex items-start justify-between gap-5">

                        <div>
                            <div
                                class="text-xs font-bold uppercase tracking-[0.18em] text-blue-400"
                            >
                                Place virtual bet
                            </div>

                            <h3 class="mt-2 text-xl font-black text-white">
                                You are placing a bet
                            </h3>
                        </div>


                        <button
                            type="button"
                            wire:click="closeBetModal"
                            class="
                                flex h-9 w-9 shrink-0 items-center justify-center
                                rounded-full border border-slate-700
                                bg-slate-900/70 text-xl text-slate-400
                                transition hover:border-slate-500 hover:text-white
                            "
                            aria-label="Close"
                        >
                            ×
                        </button>

                    </div>
                </div>


                <div class="p-6">

                    <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Market
                    </div>

                    <div class="mt-1 text-base font-bold leading-6 text-white">
                        {{ $betMarketTitle }}
                    </div>


                    <div class="mt-5 text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Selected outcome
                    </div>

                    <div
                        class="mt-2 rounded-xl border border-blue-500/30 bg-blue-500/10 px-4 py-3 text-sm font-bold text-blue-200"
                    >
                        {{ $betOutcomeTitle }}
                    </div>


                    <div class="mt-6">

                        <label
                            for="bet-amount"
                            class="text-sm font-semibold text-slate-200"
                        >
                            Bet amount
                        </label>


                        <div class="relative mt-2">

                            <div
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-lg font-bold text-slate-500"
                            >
                                $
                            </div>

                            <input
                                id="bet-amount"
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
                                    text-lg font-bold text-white
                                    outline-none
                                    transition
                                    placeholder:text-slate-600
                                    focus:border-blue-500
                                    focus:ring-2
                                    focus:ring-blue-500/20
                                "
                            >

                        </div>


                        <div class="mt-2 flex items-center justify-between gap-3">

                            <div class="text-xs text-slate-500">
                                Minimum $0.01
                            </div>

                            <div class="text-xs text-slate-500">
                                Maximum $10,000.00
                            </div>

                        </div>


                        @error('betAmount')

                        <div
                            class="mt-3 rounded-lg border border-rose-500/20 bg-rose-500/10 px-3 py-2 text-sm text-rose-300"
                        >
                            {{ $message }}
                        </div>

                        @enderror

                    </div>


                    <div class="mt-7 flex items-center justify-end gap-3">

                        <button
                            type="button"
                            wire:click="closeBetModal"
                            wire:loading.attr="disabled"
                            wire:target="placeBet"
                            class="
                                rounded-xl border border-slate-700
                                bg-slate-800 px-5 py-3
                                text-sm font-semibold text-slate-300
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
                                bg-blue-600 px-5 py-3
                                text-sm font-bold text-white
                                shadow-lg shadow-blue-600/20
                                transition
                                hover:bg-blue-500
                                disabled:cursor-wait
                                disabled:opacity-60
                            "
                        >
                            <span wire:loading.remove wire:target="placeBet">
                                Place bet
                            </span>

                            <span wire:loading wire:target="placeBet">
                                Placing...
                            </span>
                        </button>

                    </div>

                </div>

            </div>

        </div>

    @endif

</div>
