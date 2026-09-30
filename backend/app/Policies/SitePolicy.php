<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return $user->roleIn($site->workspace_id) !== null;
    }

    // Called as authorize('create', [Site::class, $workspace]).
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->canEdit() ?? false;
    }

    public function update(User $user, Site $site): bool
    {
        return $user->roleIn($site->workspace_id)?->canEdit() ?? false;
    }

    // Deleting a site takes every link and click under it along, so it stays
    // with owners even though editors may change everything else.
    public function delete(User $user, Site $site): bool
    {
        return $user->roleIn($site->workspace_id)?->isOwner() ?? false;
    }
}
