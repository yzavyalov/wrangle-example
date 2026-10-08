<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutcomeTranslation extends Model
{
    protected $fillable = [
        'market_outcome_id',
        'locale',
        'title',
    ];

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(
            MarketOutcome::class,
            'market_outcome_id'
        );
    }
}
