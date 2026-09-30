<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\PaddleWebhookController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

// Публічні маршрути
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/config', [ConfigController::class, 'index']);

// The hosted edition's price list; a 404 wherever nothing is sold.
Route::get('/plans', [PlanController::class, 'index'])->middleware('billing');

// Paddle calls this, signed. Behind the billing switch (404 when off), and
// outside the general throttle: Paddle's own retries would trip it.
Route::post('/paddle/webhook', PaddleWebhookController::class)
    ->middleware(['billing', 'throttle:paddle-webhook'])
    ->withoutMiddleware('throttle:api');

// Захищені маршрути
Route::middleware(['auth:sanctum', 'key.scope'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Keys are managed from a signed-in session only, never with a key.
    Route::middleware('session')->group(function () {
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy'])->whereNumber('id');
    });

    Route::apiResource('workspaces', WorkspaceController::class);

    Route::middleware('billing')->group(function () {
        Route::get('/workspaces/{workspace}/billing', [BillingController::class, 'show']);

        // Spending and cancelling need a person at a keyboard, never an API
        // key; and each reaches out to Paddle, hence the tight limit.
        Route::middleware(['session', 'throttle:10,1'])->group(function () {
            Route::post('/workspaces/{workspace}/billing/checkout', [BillingController::class, 'checkout']);
            Route::post('/workspaces/{workspace}/billing/change', [BillingController::class, 'change']);
            Route::post('/workspaces/{workspace}/billing/cancel', [BillingController::class, 'cancel']);
            Route::post('/workspaces/{workspace}/billing/resume', [BillingController::class, 'resume']);
            Route::get('/workspaces/{workspace}/billing/payment-method', [BillingController::class, 'paymentMethod']);
        });
    });
    Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
    Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
    Route::patch('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update']);
    Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/webhooks', [WebhookController::class, 'index']);
    Route::post('/workspaces/{workspace}/webhooks', [WebhookController::class, 'store']);
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret']);
    // Calls out to a customer-supplied address, hence the tight limit.
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1');
    Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);

    Route::apiResource('sites', SiteController::class);
    Route::post('/sites/{site}/links/import', [LinkController::class, 'import']);

    Route::get('/sites/{site}/domain', [DomainController::class, 'show']);
    Route::post('/sites/{site}/domain', [DomainController::class, 'store']);
    // Both make outbound lookups for a customer-supplied host, hence the tight limit.
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/domains/{domain}/check', [DomainController::class, 'check'])->middleware('throttle:10,1');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy']);

    Route::apiResource('sites.links', LinkController::class)->shallow();
    Route::patch('/links/{link}/toggle', [LinkController::class, 'toggle']);

    Route::post('/conversions', [ConversionController::class, 'store']);
    Route::get('/sites/{site}/conversions', [ConversionController::class, 'index']);

    Route::get('/sites/{site}/analytics', [AnalyticsController::class, 'site']);
    Route::get('/links/{link}/analytics', [AnalyticsController::class, 'link']);
    // Streams up to 100k rows, hence the tighter limit.
    Route::get('/sites/{site}/analytics/export', [AnalyticsController::class, 'exportSite'])->middleware('throttle:10,1');
    Route::get('/links/{link}/analytics/export', [AnalyticsController::class, 'exportLink'])->middleware('throttle:10,1');
});
