<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

/**
 * Owners only, reading included: a webhook's URL often carries a token, and
 * its delivery log holds every payload that was sent.
 */
class WebhookPolicy
{
    // Called as authorize('viewAny', [Webhook::class, $workspace]).
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return $user->roleIn($webhook->workspace_id)?->isOwner() ?? false;
    }

    // Called as authorize('create', [Webhook::class, $workspace]).
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return $user->roleIn($webhook->workspace_id)?->isOwner() ?? false;
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return $user->roleIn($webhook->workspace_id)?->isOwner() ?? false;
    }
}
