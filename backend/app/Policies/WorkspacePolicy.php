<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }

    /** Any member may walk out. Kept separate from manageMembers on purpose. */
    public function leave(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) !== null;
    }

    /** Adding, re-roling and removing other members. */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }

    /**
     * Buying, changing and cancelling a plan. Owners only - and, like every
     * write, never the demo account (Gate::before).
     */
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->isOwner() ?? false;
    }
}
