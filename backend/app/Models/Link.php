<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Link extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_url',
        'short_code',
        'is_active',
        'expires_at',
    ];

    /**
     * The hash never leaves the server. The frontend gets has_password
     * instead, which is all it needs to render the right controls.
     */
    protected $hidden = [
        'password',
        // Loaded only so short_url can see the site's domain - it isn't part
        // of the link's own shape and would otherwise bloat every response.
        'site',
    ];

    protected $appends = [
        'has_password',
        'short_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Link $link) {
            if (empty($link->short_code)) {
                do {
                    $code = Str::random(7);
                } while (static::where('short_code', $code)->exists());

                $link->short_code = $code;
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPasswordProtected(): bool
    {
        return $this->password !== null;
    }

    /** Whether a redirect should happen at all, ignoring the password gate. */
    public function isReachable(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    protected function hasPassword(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isPasswordProtected());
    }

    /**
     * The address a visitor should actually use: the site's own domain once
     * that's verified, the app's shared /r/{code} route otherwise.
     */
    protected function shortUrl(): Attribute
    {
        return Attribute::get(function (): string {
            $domain = $this->site?->customDomain;

            return $domain?->is_verified
                ? "https://{$domain->host}/{$this->short_code}"
                : route('links.redirect', ['code' => $this->short_code]);
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(Click::class);
    }
}
