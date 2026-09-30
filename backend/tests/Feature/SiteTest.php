<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_own_sites(): void
    {
        $user = User::factory()->create();
        Site::factory()->ownedBy($user)->create(['name' => 'Mine']);
        Site::factory()->create(['name' => 'Someone Elses']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/sites');

        $response->assertOk()->assertJsonCount(1)->assertJsonFragment(['name' => 'Mine']);
    }

    public function test_user_can_create_a_site(): void
    {
        $user = User::factory()->create();

        $workspace = $user->defaultWorkspace();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sites', [
            'workspace_id' => $workspace->id,
            'name' => 'My Site',
            'domain' => 'example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('role', 'owner')
            ->assertJsonPath('workspace.id', $workspace->id);
        $this->assertDatabaseHas('sites', ['name' => 'My Site', 'workspace_id' => $workspace->id]);
    }

    public function test_user_cannot_view_another_users_site(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();

        $response = $this->actingAs($other, 'sanctum')->getJson("/api/sites/{$site->id}");

        $response->assertForbidden();
    }

    public function test_user_cannot_delete_another_users_site(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();

        $response = $this->actingAs($other, 'sanctum')->deleteJson("/api/sites/{$site->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_demo_user_cannot_create_a_site(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);

        $response = $this->actingAs($demo, 'sanctum')->postJson('/api/sites', [
            'workspace_id' => $demo->defaultWorkspace()->id,
            'name' => 'Should Not Exist',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('sites', ['name' => 'Should Not Exist']);
    }

    public function test_demo_user_can_still_read_sites(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);
        Site::factory()->ownedBy($demo)->create();

        $response = $this->actingAs($demo, 'sanctum')->getJson('/api/sites');

        $response->assertOk();
    }

    public function test_site_name_must_be_unique_per_workspace_not_globally(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Site::factory()->ownedBy($userA)->create(['name' => 'Shared Name']);

        // Same name, different workspace - allowed.
        $this->actingAs($userB, 'sanctum')->postJson('/api/sites', [
            'workspace_id' => $userB->defaultWorkspace()->id,
            'name' => 'Shared Name',
        ])->assertCreated();

        // Same name, same workspace - rejected.
        $this->actingAs($userA, 'sanctum')->postJson('/api/sites', [
            'workspace_id' => $userA->defaultWorkspace()->id,
            'name' => 'Shared Name',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_site_needs_a_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sites', ['name' => 'Orphan'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workspace_id');
    }

    public function test_a_workspace_the_caller_cannot_see_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        $user = User::factory()->create();
        $strangers = Workspace::factory()->create();

        // 422 for both - a 403 for the real one would confirm it exists.
        foreach ([$strangers->id, $strangers->id + 999] as $workspaceId) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/sites', ['workspace_id' => $workspaceId, 'name' => 'Nope'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('workspace_id');
        }

        $this->assertDatabaseMissing('sites', ['name' => 'Nope']);
    }

    public function test_a_viewer_cannot_create_a_site_in_the_workspace(): void
    {
        $viewer = User::factory()->create();
        $workspace = Workspace::factory()->withMember($viewer, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/sites', ['workspace_id' => $workspace->id, 'name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_an_editor_can_create_a_site_in_the_workspace(): void
    {
        $editor = User::factory()->create();
        $workspace = Workspace::factory()->withMember($editor, WorkspaceRole::Editor)->create();

        $this->actingAs($editor, 'sanctum')
            ->postJson('/api/sites', ['workspace_id' => $workspace->id, 'name' => 'Team Site'])
            ->assertCreated()
            ->assertJsonPath('role', 'editor');
    }

    public function test_the_list_spans_every_workspace_and_tells_the_caller_their_role_in_each(): void
    {
        $user = User::factory()->create();
        Site::factory()->ownedBy($user)->create(['name' => 'Mine']);
        Site::factory()->sharedWith($user, WorkspaceRole::Viewer)->create(['name' => 'Theirs']);
        Site::factory()->create(['name' => 'Nobody I know']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/sites')->assertOk();

        $roles = collect($response->json())->pluck('role', 'name')->all();
        $this->assertSame(['Mine' => 'owner', 'Theirs' => 'viewer'], $roles);
        $this->assertNotNull($response->json('0.workspace.name'));
    }

    public function test_an_editor_may_update_a_site_but_only_an_owner_may_delete_it(): void
    {
        $editor = User::factory()->create();
        $site = Site::factory()->sharedWith($editor, WorkspaceRole::Editor)->create(['name' => 'Before']);

        $this->actingAs($editor, 'sanctum')
            ->putJson("/api/sites/{$site->id}", ['name' => 'After'])
            ->assertOk();

        $this->actingAs($editor, 'sanctum')
            ->deleteJson("/api/sites/{$site->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'After']);
    }

    public function test_a_viewer_can_read_a_site_but_not_change_it(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->sharedWith($viewer, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/sites/{$site->id}")
            ->assertOk()->assertJsonPath('role', 'viewer');
        $this->actingAs($viewer, 'sanctum')->putJson("/api/sites/{$site->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/sites/{$site->id}")->assertForbidden();
    }
}
