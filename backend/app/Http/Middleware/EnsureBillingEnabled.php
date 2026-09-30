<?php

namespace App\Http\Middleware;

use App\Billing\PlanCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing is a switch, and off means the routes might as well not exist: a
 * self-hosted install answers 404, exactly as it did before there was any.
 */
class EnsureBillingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(PlanCatalog::class)->enabled(), 404);

        return $next($request);
    }
}
