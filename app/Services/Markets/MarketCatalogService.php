<?php

namespace App\Services\Markets;

use App\Models\Category;
use App\Models\Country;
use App\Models\Market;
use App\Models\MarketTranslation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MarketCatalogService
{
    public const PER_PAGE = 12;

    public const MARKET_STATUSES = [
        'published',
        'resolving',
        'resolved',
    ];

    public function markets(
        string $locale = '',
        string $category = '',
        string $country = '',
        string $search = '',
        string $sort = 'newest',
        int $page = 1,
    ): LengthAwarePaginator {
        $locale = $this->normalizeLocale($locale);
        $category = trim($category);
        $country = strtoupper(trim($country));
        $search = trim($search);
        $sort = $this->normalizeSort($sort);

        $query = Market::query()
            ->whereIn('status', self::MARKET_STATUSES)
            ->when($locale !== '', function (Builder $query) use ($locale) {
                $query->whereHas('translations', fn (Builder $q) => $q->where('locale', $locale));
            })
            ->when($category !== '', function (Builder $query) use ($category) {
                $query->whereHas('categories', fn (Builder $q) => $q->where('categories.slug', $category));
            })
            ->when($country !== '', function (Builder $query) use ($country) {
                $query->whereHas('countries', fn (Builder $q) => $q->where('countries.code', $country));
            })
            ->when($search !== '', function (Builder $query) use ($locale, $search) {
                $term = '%'.$search.'%';

                $query->whereHas('translations', function (Builder $q) use ($locale, $term) {
                    if ($locale !== '') {
                        $q->where('locale', $locale);
                    }

                    $q->where(function (Builder $text) use ($term) {
                        $text->where('title', 'like', $term)
                            ->orWhere('description', 'like', $term);
                    });
                });
            })
            ->with([
                'translations',
                'outcomes' => function ($query) {
                    $query->orderBy('sort_order')
                        ->orderBy('id')
                        ->with('translations')
                        ->withSum('virtualBets as bet_pool', 'amount');
                },
                'categories',
            ])
            ->withCount([
                'virtualBets as bets_count',
                'virtualBets as traders_count' => function ($query) {
                    $query->selectRaw('COUNT(DISTINCT visitor_id)');
                },
            ]);

        if ($sort === 'ending') {
            $query->orderByRaw('finish_at IS NULL')
                ->orderBy('finish_at')
                ->orderByDesc('published_at')
                ->orderByDesc('id');
        } else {
            $query->orderByDesc('published_at')
                ->orderByDesc('id');
        }

        return $query->paginate(
            perPage: self::PER_PAGE,
            page: max(1, $page),
        );
    }

    public function locales(): Collection
    {
        return MarketTranslation::query()
            ->whereHas('market', fn (Builder $q) => $q->whereIn('status', self::MARKET_STATUSES))
            ->whereNotNull('locale')
            ->where('locale', '!=', '')
            ->distinct()
            ->orderBy('locale')
            ->pluck('locale')
            ->map(fn ($locale) => strtolower(trim((string) $locale)))
            ->filter()
            ->unique()
            ->values();
    }

    public function categories(string $locale = ''): Collection
    {
        $locale = $this->normalizeLocale($locale);

        return Category::query()
            ->whereHas('markets', function (Builder $query) use ($locale) {
                $query->whereIn('markets.status', self::MARKET_STATUSES);

                if ($locale !== '') {
                    $query->whereHas('translations', fn (Builder $q) => $q->where('locale', $locale));
                }
            })
            ->orderBy('name')
            ->get(['categories.id', 'categories.name', 'categories.slug']);
    }

    public function countries(string $locale = ''): Collection
    {
        $locale = $this->normalizeLocale($locale);

        return Country::query()
            ->whereHas('markets', function (Builder $query) use ($locale) {
                $query->whereIn('markets.status', self::MARKET_STATUSES);

                if ($locale !== '') {
                    $query->whereHas('translations', fn (Builder $q) => $q->where('locale', $locale));
                }
            })
            ->orderBy('name')
            ->get(['countries.id', 'countries.code', 'countries.name']);
    }

    public function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        if ($locale === '') {
            return '';
        }

        return preg_match('/^[a-z0-9_-]{2,20}$/', $locale) ? $locale : '';
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, ['newest', 'ending'], true) ? $sort : 'newest';
    }
}
