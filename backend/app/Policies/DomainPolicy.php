<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\Site;
use App\Models\User;

/**
 * A domain decides where a whole brand resolves, so attaching, verifying and
 * removing one is owner-only. Reading it (including the connection check) is
 * open to every member.
 */
class DomainPolicy
{
    public function view(User $user, Domain $domain): bool
    {
        return $user->roleIn($domain->site->workspace_id) !== null;
    }

    // Called as authorize('create', [Domain::class, $site]).
    public function create(User $user, Site $site): bool
    {
        return $user->roleIn($site->workspace_id)?->isOwner() ?? false;
    }

    public function update(User $user, Domain $domain): bool
    {
        return $user->roleIn($domain->site->workspace_id)?->isOwner() ?? false;
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $user->roleIn($domain->site->workspace_id)?->isOwner() ?? false;
    }
}
