<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Country extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class);
    }
}
