<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'description',
        'conversion_tracking',
    ];

    protected $casts = [
        'conversion_tracking' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /**
     * Not named domain() on purpose: sites.domain is an existing free-text
     * label column, and an identically named relation would be shadowed by
     * it. The two should be reconciled when custom domains actually ship
     * (the label likely stops earning its place then).
     */
    public function customDomain(): HasOne
    {
        return $this->hasOne(Domain::class);
    }
}
