<?php

use App\Http\Middleware\EnforceKeyScope;
use App\Http\Middleware\RequireSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        ]);

        // The app sits behind a reverse proxy in every deployment (Railway's
        // edge, or our own nginx in docker-compose) — without this, click
        // analytics would record the proxy's IP instead of the visitor's.
        $middleware->trustProxies(at: '*');
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
