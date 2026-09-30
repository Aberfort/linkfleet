<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ApiKeyScope;
use App\Support\GeoIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One reader per request, opened only if a click actually needs it.
        $this->app->singleton(GeoIp::class);

        // Cashier would register its own /paddle/webhook whether or not
        // billing is on, and guard it only if a secret happens to be set.
        // The webhook is declared in routes/api.php instead.
        Cashier::ignoreRoutes();

        // A payment that failed once is retried by Paddle for days; the
        // workspace keeps its plan meanwhile instead of losing it on the
        // first declined card.
        Cashier::keepPastDueSubscriptionsActive();
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

        // A pixel fires once per page view on someone else's site, so the
        // ceiling is generous - but it is public and unauthenticated.
        RateLimiter::for('conversion-pixel', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Paddle delivers from a handful of addresses in bursts.
        RateLimiter::for('paddle-webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

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
