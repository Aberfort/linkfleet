<?php

namespace App\Policies;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;

class LinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Link $link): bool
    {
        return $user->roleIn($link->site->workspace_id) !== null;
    }

    // Called as authorize('create', [Link::class, $site]) - a link can only
    // be created under a site in a workspace where the user may edit.
    public function create(User $user, Site $site): bool
    {
        return $user->roleIn($site->workspace_id)?->canEdit() ?? false;
    }

    public function update(User $user, Link $link): bool
    {
        return $user->roleIn($link->site->workspace_id)?->canEdit() ?? false;
    }

    public function delete(User $user, Link $link): bool
    {
        return $user->roleIn($link->site->workspace_id)?->canEdit() ?? false;
    }
}
