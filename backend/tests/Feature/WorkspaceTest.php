<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_gives_the_new_user_a_workspace_of_their_own(): void
    {
        config(['features.registration_enabled' => true]);

        $this->postJson('/api/register', [
            'name' => 'Newcomer',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame([WorkspaceRole::Owner], array_values($user->workspaceRoles()));
    }

    public function test_the_list_holds_only_workspaces_the_caller_belongs_to(): void
    {
        $user = User::factory()->create();
        Workspace::factory()->withMember($user, WorkspaceRole::Owner)->create(['name' => 'Mine']);
        Workspace::factory()->withMember($user, WorkspaceRole::Viewer)->create(['name' => 'Shared']);
        Workspace::factory()->create(['name' => 'Someone Elses']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/workspaces')->assertOk();

        $this->assertSame(
            ['Mine' => 'owner', 'Shared' => 'viewer'],
            collect($response->json())->pluck('role', 'name')->all()
        );
    }

    public function test_the_list_counts_members_and_sites(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->withMember($user)->create();
        Workspace::find($workspace->id)->members()->attach(User::factory()->create(), ['role' => 'viewer']);
        Site::factory()->count(3)->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/workspaces')
            ->assertJsonPath('0.members_count', 2)
            ->assertJsonPath('0.sites_count', 3);
    }

    public function test_creating_a_workspace_makes_the_creator_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', ['name' => 'Acme Agency'])
            ->assertCreated()
            ->assertJsonPath('role', 'owner')
            ->assertJsonPath('members_count', 1);

        $workspace = Workspace::where('name', 'Acme Agency')->sole();
        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($workspace));
    }

    public function test_only_an_owner_may_rename_a_workspace(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $workspace = Workspace::factory()->withMember($owner)->create(['name' => 'Old']);
        $workspace->members()->attach($editor, ['role' => 'editor']);

        $this->actingAs($editor, 'sanctum')->putJson("/api/workspaces/{$workspace->id}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson("/api/workspaces/{$workspace->id}", ['name' => 'New'])
            ->assertOk()->assertJsonPath('name', 'New');
    }

    public function test_only_an_owner_may_delete_a_workspace_and_its_sites_go_with_it(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $workspace = Workspace::factory()->withMember($owner)->create();
        $workspace->members()->attach($editor, ['role' => 'editor']);
        $site = Site::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($editor, 'sanctum')->deleteJson("/api/workspaces/{$workspace->id}")->assertForbidden();
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/workspaces/{$workspace->id}")->assertNoContent();
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_a_stranger_cannot_even_read_a_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/workspaces/{$workspace->id}")->assertForbidden();
    }

    public function test_the_demo_account_cannot_create_or_delete_workspaces(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();
        $workspace = $demo->defaultWorkspace();

        $this->actingAs($demo, 'sanctum')->postJson('/api/workspaces', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($demo, 'sanctum')->putJson("/api/workspaces/{$workspace->id}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($demo, 'sanctum')->deleteJson("/api/workspaces/{$workspace->id}")->assertForbidden();
        $this->actingAs($demo, 'sanctum')->getJson('/api/workspaces')->assertOk();
    }

    public function test_the_migration_backfill_shape_is_what_the_default_workspace_recreates(): void
    {
        // defaultWorkspace() is what registration, the seeders and the
        // factories all lean on; calling it twice must not multiply workspaces.
        $user = User::factory()->create();

        $first = $user->defaultWorkspace();
        $second = $user->defaultWorkspace();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Workspace::count());
    }
}
