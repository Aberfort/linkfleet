<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }

    /** Attaches the user as a member once the workspace exists. */
    public function withMember(User $user, WorkspaceRole $role = WorkspaceRole::Owner): static
    {
        return $this->afterCreating(
            fn (Workspace $workspace) => $workspace->members()->attach($user->id, ['role' => $role->value])
        );
    }
}
