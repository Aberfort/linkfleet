<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->company(),
            'domain' => fake()->domainName(),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * A site in the user's own workspace - the shorthand tests reach for
     * when what matters is "this user can see it".
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['workspace_id' => $user->defaultWorkspace()->id]);
    }

    /** A site in a workspace the user has been given the stated role in. */
    public function sharedWith(User $user, WorkspaceRole $role): static
    {
        return $this->state(fn () => [
            'workspace_id' => Workspace::factory()->withMember($user, $role),
        ]);
    }
}
