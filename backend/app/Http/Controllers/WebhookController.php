<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEvent;
use App\Http\Requests\StoreWebhookRequest;
use App\Http\Requests\UpdateWebhookRequest;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\WebhookSender;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function index(Workspace $workspace)
    {
        $this->authorize('viewAny', [Webhook::class, $workspace]);

        return $workspace->webhooks()->with('latestDelivery')->orderBy('id')->get()
            ->map(fn (Webhook $webhook) => $this->present($webhook));
    }

    /** The secret is in this response and nowhere else until it is rotated. */
    public function store(StoreWebhookRequest $request, Workspace $workspace)
    {
        $max = (int) config('features.webhooks.max_per_workspace');

        if ($workspace->webhooks()->count() >= $max) {
            throw ValidationException::withMessages([
                'url' => "У workspace може бути не більше {$max} вебхуків.",
            ]);
        }

        $webhook = $workspace->webhooks()->create($request->validated());

        return response()->json($this->present($webhook->refresh()->makeVisible('secret')), 201);
    }

    public function show(Webhook $webhook)
    {
        $this->authorize('view', $webhook);

        return $this->present($webhook->load('latestDelivery'));
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook)
    {
        $webhook->update($request->validated());

        return $this->present($webhook->load('latestDelivery'));
    }

    public function destroy(Webhook $webhook)
    {
        $this->authorize('delete', $webhook);

        $webhook->delete();

        return response()->noContent();
    }

    /** A leaked secret is fixed by replacing it; the old one stops verifying at once. */
    public function rotateSecret(Webhook $webhook)
    {
        $this->authorize('update', $webhook);

        $webhook->forceFill(['secret' => Webhook::generateSecret()])->save();

        return $this->present($webhook->load('latestDelivery')->makeVisible('secret'));
    }

    /**
     * Sends a `ping` right now, on this request, and reports what happened.
     * The API call itself succeeds either way: a receiver that refused it is
     * an answer, not an error.
     */
    public function test(Webhook $webhook, WebhookSender $sender)
    {
        $this->authorize('update', $webhook);

        $outcome = $sender->send($webhook, [
            'id' => 'evt_'.Str::uuid(),
            'type' => WebhookEvent::Ping->value,
            'created_at' => now()->toIso8601String(),
            'workspace_id' => $webhook->workspace_id,
            'data' => ['message' => 'Test event from LinkFleet.'],
        ]);

        // Explicit: a model that was just created would otherwise answer 201.
        return response()->json($outcome->delivery, 200);
    }

    public function deliveries(Webhook $webhook)
    {
        $this->authorize('view', $webhook);

        return $webhook->deliveries()->latest('id')->limit(50)->get();
    }

    /** @return array<string, mixed> */
    private function present(Webhook $webhook): array
    {
        $delivery = $webhook->relationLoaded('latestDelivery') ? $webhook->latestDelivery : null;

        return [
            ...$webhook->toArray(),
            'latest_delivery' => $delivery ? [
                'success' => $delivery->success,
                'status_code' => $delivery->status_code,
                'event' => $delivery->event,
                'created_at' => $delivery->created_at,
            ] : null,
        ];
    }
}
