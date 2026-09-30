<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Click extends Model
{
    use HasFactory;

    // Immutable log row - written once by RecordLinkClick, never updated.
    const UPDATED_AT = null;

    // Only ever written internally by App\Actions\RecordLinkClick, from
    // values it has already derived/parsed itself - never a raw request
    // payload, so mass-assigning these is safe.
    protected $fillable = [
        'link_id',
        'token',
        'ip_hash',
        'referrer',
        'user_agent',
        'browser',
        'browser_version',
        'platform',
        'device_type',
    ];

    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
