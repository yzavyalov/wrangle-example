<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    protected $fillable = [
        'public_id',
        'wrangle_market_id',
        'status',
        'source_locale',
        'finish_at',
        'published_at',
        'resolved_at',
        'winning_outcome_id',
        'winning_pool',
        'losing_pool',
        'profit_per_dollar',
        'return_per_dollar',
    ];

    protected function casts(): array
    {
        return [
            'finish_at' => 'datetime',
            'published_at' => 'datetime',
            'resolved_at' => 'datetime',
            'winning_pool' => 'decimal:2',
            'losing_pool' => 'decimal:2',
            'profit_per_dollar' => 'decimal:8',
            'return_per_dollar' => 'decimal:8',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MarketTranslation::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(MarketOutcome::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(MarketSource::class);
    }

    public function virtualBets(): HasMany
    {
        return $this->hasMany(VirtualBet::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class);
    }

    public function countryGroups(): BelongsToMany
    {
        return $this->belongsToMany(CountryGroup::class);
    }

    public function winningOutcome(): BelongsTo
    {
        return $this->belongsTo(
            MarketOutcome::class,
            'winning_outcome_id'
        );
    }
}
