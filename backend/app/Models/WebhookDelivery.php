<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to deliver one event: an append-only log line. */
class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'success' => 'boolean',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
