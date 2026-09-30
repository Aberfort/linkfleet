<?php

namespace App\Http\Middleware;

use App\Support\ApiKeyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the few endpoints an API key must never reach - chiefly key
 * management itself. Otherwise a leaked write key could mint more keys and
 * outlive its own revocation.
 */
class RequireSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiKeyScope::isKey($request->user()?->currentAccessToken())) {
            abort(403, 'Цю дію можна виконати лише з-під облікового запису, а не через API-ключ.');
        }

        return $next($request);
    }
}
