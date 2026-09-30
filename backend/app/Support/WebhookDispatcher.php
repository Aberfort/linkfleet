<?php

namespace App\Support;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Site;
use App\Models\Webhook;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns "something happened to a link" into queued deliveries. Sits on the
 * redirect path, so it is built to cost one query when nobody is listening
 * and never to break the request when something goes wrong.
 */
class WebhookDispatcher
{
    /**
     * @param  Closure(): array<string, mixed>  $data  built only if someone subscribes
     */
    public static function dispatch(WebhookEvent $event, int $siteId, Closure $data): void
    {
        try {
            $webhooks = Webhook::query()
                ->where('is_active', true)
                ->whereIn('workspace_id', Site::query()->select('workspace_id')->whereKey($siteId))
                ->get()
                ->filter(fn (Webhook $webhook) => $webhook->subscribesTo($event));

            if ($webhooks->isEmpty()) {
                return;
            }

            $payload = $data();
            $id = 'evt_'.Str::uuid();
            $createdAt = now()->toIso8601String();

            foreach ($webhooks as $webhook) {
                DeliverWebhook::dispatch($webhook->id, [
                    'id' => $id,
                    'type' => $event->value,
                    'created_at' => $createdAt,
                    'workspace_id' => $webhook->workspace_id,
                    'data' => $payload,
                ]);
            }
        } catch (Throwable $e) {
            // A broken webhook must never cost someone their redirect or
            // their link edit.
            report($e);
        }
    }
}
