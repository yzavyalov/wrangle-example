<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSource extends Model
{
    protected $fillable = [
        'market_id',
        'url',
        'sort_order',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
