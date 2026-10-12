<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\Market;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

new class extends Component
{
    /**
     * Hard server-side limit.
     *
     * This is not a public Livewire property, so the client
     * cannot request an arbitrary number of markets.
     */
    private const PAGE_SIZE = 12;

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
     *
     * Category, country, sorting and language are all
     * validated and applied on the server.
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

            /**
             * Counts are calculated in SQL.
             *
             * We do not load the full virtual_bets collection
             * into every market card.
             */
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

    /**
     * Clear all market filters that affect the result set.
     */
    public function clearFilters(): void
    {
        $this->category = '';
        $this->country = '';
    }

    public function marketTitle(Market $market): string
    {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere('locale', $locale)
            ?->title
            ?? $market->translations
            ->firstWhere('locale', 'en')
            ?->title
            ?? 'Untitled market';
    }

    public function marketDescription(Market $market): ?string
    {
        $locale = $this->validLocale();

        return $market->translations
            ->firstWhere('locale', $locale)
            ?->description
            ?? $market->translations
                ->firstWhere('locale', 'en')
                ?->description;
    }

    public function outcomeTitle($outcome): string
    {
        $locale = $this->validLocale();

        return $outcome->translations
            ->firstWhere('locale', $locale)
            ?->title
            ?? $outcome->translations
            ->firstWhere('locale', 'en')
            ?->title
            ?? 'Outcome';
    }

    public function marketPool(Market $market): float
    {
        return (float) $market->outcomes->sum(
            fn ($outcome) =>
            (float) ($outcome->bet_pool ?? 0)
        );
    }

    public function outcomePool($outcome): float
    {
        return (float) ($outcome->bet_pool ?? 0);
    }

    public function outcomePercent(
        Market $market,
               $outcome
    ): float {
        $total = $this->marketPool($market);

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

    public function betCount(Market $market): int
    {
        return (int) ($market->bets_count ?? 0);
    }

    public function traderCount(Market $market): int
    {
        return (int) ($market->traders_count ?? 0);
    }

    public function categoryName(Market $market): string
    {
        return $market->categories
            ->first()
            ?->name
            ?? 'Market';
    }

    public function timeLeft(Market $market): string
    {
        if (! $market->finish_at) {
            return 'No deadline';
        }

        if ($market->status === 'resolved') {
            return 'Finished';
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

    public function statusLabel(Market $market): string
    {
        return match ($market->status) {
            'published' => 'Open',
            'resolving' => 'Resolving',
            'resolved' => 'Resolved',

            default => ucfirst(
                $market->status
            ),
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
                    outline-none
                    transition
                    hover:border-slate-700
                    focus:border-blue-500
                "
                aria-label="Language"
            >
                <option value="en">
                    EN
                </option>

                <option value="es">
                    ES
                </option>
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

                {{-- COUNTRY --}}
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
                            outline-none
                            transition
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


                {{-- LANGUAGE --}}
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
                            outline-none
                            transition
                            hover:border-slate-700
                            focus:border-blue-500
                        "
                    >
                        <option value="en">
                            English
                        </option>

                        <option value="es">
                            Español
                        </option>
                    </select>

                </div>


                {{-- SORT --}}
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
                            outline-none
                            transition
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

                    {{-- =========================================
                         CARD IMAGE / FALLBACK
                    ========================================== --}}
                    <div
                        class="relative min-h-44 overflow-hidden border-b border-slate-800 bg-gradient-to-br from-blue-950 via-[#102b4b] to-[#071422] p-4"
                    >

                        @if ($market->image_url)

                            <img
                                src="{{ $market->image_url }}"
                                alt=""
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                class="
                                    absolute inset-0
                                    h-full w-full
                                    object-cover
                                "
                                onerror="this.style.display='none'"
                            >

                            {{-- Dark overlays keep labels readable. --}}
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


                        <div
                            class="relative z-10 flex items-start justify-between gap-3"
                        >

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


                        <div
                            class="absolute bottom-4 right-4 z-10"
                        >
                            <span
                                class="rounded-lg border border-white/10 bg-black/55 px-3 py-1.5 text-xs font-medium text-slate-100 shadow-sm backdrop-blur-md"
                            >
                                ◷ {{ $this->timeLeft($market) }}
                            </span>
                        </div>

                    </div>


                    {{-- =========================================
                         CARD CONTENT
                    ========================================== --}}
                    <div class="p-4">

                        <h2
                            class="min-h-14 text-lg font-bold leading-6 text-white"
                        >
                            {{ $this->marketTitle($market) }}
                        </h2>


                        @if ($this->marketDescription($market))

                            <p
                                class="mt-2 line-clamp-2 text-sm leading-5 text-slate-400"
                            >
                                {{ $this->marketDescription($market) }}
                            </p>

                        @endif


                        {{-- =====================================
                             OUTCOMES
                        ====================================== --}}
                        <div class="mt-4 space-y-2">

                            @foreach ($market->outcomes as $outcome)

                                @php
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


                                <div
                                    wire:key="outcome-{{ $market->public_id }}-{{ $loop->index }}"
                                    class="
                                        relative overflow-hidden
                                        rounded-xl border

                                        {{ $outcome->is_winner
                                            ? 'border-emerald-400/80 bg-emerald-500/10'
                                            : 'border-slate-700/70 bg-slate-800/45'
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

                                            <div
                                                class="flex items-center gap-2"
                                            >

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


                                        <div
                                            class="text-right text-xs text-slate-400"
                                        >
                                            ${{ number_format($outcomePool, 2) }}
                                        </div>

                                    </div>

                                </div>

                            @endforeach

                        </div>


                        {{-- =====================================
                             CARD FOOTER
                        ====================================== --}}
                        <div
                            class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-slate-800 pt-4"
                        >

                            <div class="text-xs text-slate-400">
                                <span
                                    class="font-semibold text-slate-200"
                                >
                                    {{ number_format($this->traderCount($market)) }}
                                </span>

                                traders
                            </div>


                            <div class="text-xs text-slate-400">
                                <span
                                    class="font-semibold text-slate-200"
                                >
                                    ${{ number_format($pool, 2) }}
                                </span>

                                volume
                            </div>


                            <div class="text-xs text-slate-500">
                                {{ number_format($this->betCount($market)) }}
                                bets
                            </div>


                            <button
                                type="button"
                                class="
                                    ml-auto rounded-lg
                                    bg-blue-600 px-4 py-2.5
                                    text-sm font-semibold text-white
                                    transition
                                    hover:bg-blue-500
                                "
                            >
                                {{ $market->status === 'resolved'
                                    ? 'View results'
                                    : 'View market'
                                }}

                                →
                            </button>

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


        <footer
            class="py-10 text-center text-xs text-slate-600"
        >
            Maximum {{ self::PAGE_SIZE }} markets per request
        </footer>

    </main>

</div>
