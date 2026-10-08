<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketOutcome extends Model
{
    protected $fillable = [
        'market_id',
        'wrangle_answer_id',
        'sort_order',
        'is_winner',
    ];

    protected function casts(): array
    {
        return [
            'is_winner' => 'boolean',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(
            OutcomeTranslation::class,
            'market_outcome_id'
        );
    }

    public function virtualBets(): HasMany
    {
        return $this->hasMany(
            VirtualBet::class,
            'market_outcome_id'
        );
    }
}
