<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Webhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'events',
        'is_active',
    ];

    /**
     * Never serialised by accident. The two responses that are meant to show
     * it (creation and rotation) call makeVisible() explicitly.
     */
    protected $hidden = [
        'secret',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        // Encrypted at rest, unlike an API key: signing needs the original.
        'secret' => 'encrypted',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Webhook $webhook) {
            $webhook->secret ??= self::generateSecret();
        });
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany();
    }
}
