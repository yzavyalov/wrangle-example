<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualBet extends Model
{
    protected $fillable = [
        'market_id',
        'market_outcome_id',
        'visitor_id',
        'amount',
        'status',
        'profit',
        'payout',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'profit' => 'decimal:2',
            'payout' => 'decimal:2',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(
            MarketOutcome::class,
            'market_outcome_id'
        );
    }
}
