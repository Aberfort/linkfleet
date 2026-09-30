<?php

namespace App\Http\Middleware;

use App\Support\ApiKeyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an API key to what it was issued for. A read key may only make
 * requests that cannot change anything; anything else needs a write key.
 * Full sessions are untouched.
 */
class EnforceKeyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (ApiKeyScope::isKey($token) && ! $request->isMethodSafe() && ! $token->can('write')) {
            abort(403, 'Цей ключ має доступ лише для читання.');
        }

        return $next($request);
    }
}
