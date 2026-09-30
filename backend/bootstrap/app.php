<?php

use App\Http\Middleware\EnforceKeyScope;
use App\Http\Middleware\EnsureBillingEnabled;
use App\Http\Middleware\RealIpFromHeader;
use App\Http\Middleware\RequireSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->throttleApi();

        $middleware->alias([
            'key.scope' => EnforceKeyScope::class,
            'session' => RequireSession::class,
            'billing' => EnsureBillingEnabled::class,
        ]);

        // Nothing here keeps a cookie or a session. The API is Bearer-token
        // only, and the routes a visitor meets (the redirect, the password
        // gate, QR codes, the pixel) remember nothing about them. Left on, the
        // web group handed a session and an XSRF cookie to every person who
        // merely followed a short link - needless, and at odds with a service
        // that promises not to track. The gate posts without a CSRF token
        // because there is no session for a forged post to act on.
        $middleware->web(remove: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
        ]);

        // The app sits behind a reverse proxy in every deployment (Railway's
        // edge, or our own nginx in docker-compose) — without this, click
        // analytics would record the proxy's IP instead of the visitor's.
        $middleware->trustProxies(at: '*');

        // Before TrustProxies reads REMOTE_ADDR, so everything after agrees
        // about who the visitor is. A no-op unless CLIENT_IP_HEADER is set.
        $middleware->prepend(RealIpFromHeader::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json(['message' => 'Неавтентифікований'], 401);
        });

        // Every api/* route is JSON-only - always render errors as JSON
        // there, regardless of what Accept/X-Requested-With headers (or
        // their absence) a client happens to send.
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
