<?php

namespace App\Observers;

use App\Enums\WebhookEvent;
use App\Models\Link;
use App\Support\WebhookDispatcher;
use App\Support\WebhookPayload;

class LinkObserver
{
    public function created(Link $link): void
    {
        $this->emit(WebhookEvent::LinkCreated, $link);
    }

    public function updated(Link $link): void
    {
        // Every click increments clicks_count, which is an update as far as
        // Eloquent is concerned. That is what link.clicked is for.
        if (array_diff(array_keys($link->getChanges()), ['clicks_count', 'updated_at']) === []) {
            return;
        }

        $this->emit(WebhookEvent::LinkUpdated, $link);
    }

    public function deleted(Link $link): void
    {
        $this->emit(WebhookEvent::LinkDeleted, $link);
    }

    private function emit(WebhookEvent $event, Link $link): void
    {
        WebhookDispatcher::dispatch($event, $link->site_id, fn () => ['link' => WebhookPayload::link($link)]);
    }
}
