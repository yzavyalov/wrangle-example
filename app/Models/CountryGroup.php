<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CountryGroup extends Model
{
    protected $fillable = [
        'wrangle_country_group_id',
        'name',
        'slug',
    ];

    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class);
    }
}
