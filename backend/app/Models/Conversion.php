<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something a visitor did after clicking a link: signed up, bought. Append-only. */
class Conversion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const SOURCE_SERVER = 'server';

    public const SOURCE_PIXEL = 'pixel';

    protected $guarded = [];

    protected $casts = [
        // Kept as a string, never a float: this is money.
        'value' => 'decimal:2',
    ];

    public function click(): BelongsTo
    {
        return $this->belongsTo(Click::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
