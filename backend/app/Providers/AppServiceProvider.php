<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ApiKeyScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every token this app issues starts with lf_, so a leaked one is
        // recognisable on sight and to secret scanners. Sanctum only reads
        // the prefix when minting, so tokens issued earlier keep working.
        config(['sanctum.token_prefix' => config('sanctum.token_prefix') ?: 'lf_']);

        RateLimiter::for('api', function (Request $request) {
            // The sanctum guard is asked by name so this does not depend on
            // middleware ordering: it works today because Laravel ranks
            // Authenticate ahead of ThrottleRequests, but nothing here says so.
            $user = $request->user('sanctum');
            $token = $user?->currentAccessToken();

            if (ApiKeyScope::isKey($token)) {
                return Limit::perMinute((int) config('features.api_key_rate_limit'))->by('key:'.$token->id);
            }

            return Limit::perMinute((int) config('features.api_rate_limit'))
                ->by($user ? 'user:'.$user->id : $request->ip());
        });

        // Not backed by a model, so there is nothing for a policy to hang
        // off; defining it lets the demo account's read-only rule (the
        // Gate::before below) apply to key management like everything else.
        Gate::define('manage-api-keys', fn (User $user) => true);

        // Single source of truth for "the demo account is read-only" -
        // every Policy's write abilities fall through here regardless of
        // resource type, instead of repeating an is_demo check in each one.
        Gate::before(function ($user, string $ability) {
            if ($user->is_demo && ! in_array($ability, ['viewAny', 'view'], true)) {
                return false;
            }

            return null;
        });
    }
}
