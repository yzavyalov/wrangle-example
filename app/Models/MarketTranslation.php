<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTranslation extends Model
{
    protected $fillable = [
        'market_id',
        'locale',
        'title',
        'description',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
