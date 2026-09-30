<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Support\WebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Seconds to wait before attempt 2, 3, 4 and 5. */
    private const BACKOFF = [10, 60, 300, 1800];

    /** @param  array<string, mixed>  $envelope */
    public function __construct(public int $webhookId, public array $envelope) {}

    public function handle(WebhookSender $sender): void
    {
        $webhook = Webhook::find($this->webhookId);

        // Deleted or switched off while this sat in the queue.
        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        $outcome = $sender->send($webhook, $this->envelope, $this->attempts());

        // Released rather than thrown: each attempt is already in the
        // delivery log, so there is nothing for failed_jobs to add, and a
        // synchronous queue must never turn a bad receiver into an error
        // for whoever clicked the link.
        if ($outcome->retry && $this->attempts() < $this->tries) {
            $this->release(self::BACKOFF[$this->attempts() - 1] ?? end(self::BACKOFF));
        }
    }
}
