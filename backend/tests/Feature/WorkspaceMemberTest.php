<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMemberTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWith(User $user, WorkspaceRole $role = WorkspaceRole::Owner): Workspace
    {
        return Workspace::factory()->withMember($user, $role)->create();
    }

    public function test_any_member_can_see_who_else_is_in_the_workspace(): void
    {
        $viewer = User::factory()->create(['name' => 'Vera']);
        $workspace = $this->workspaceWith(User::factory()->create(['name' => 'Olga']));
        $workspace->members()->attach($viewer, ['role' => 'viewer']);

        $response = $this->actingAs($viewer, 'sanctum')->getJson("/api/workspaces/{$workspace->id}/members")->assertOk();

        $this->assertSame(['Olga' => 'owner', 'Vera' => 'viewer'], collect($response->json())->pluck('role', 'name')->all());
    }

    public function test_a_stranger_cannot_list_members(): void
    {
        $workspace = $this->workspaceWith(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/workspaces/{$workspace->id}/members")->assertForbidden();
    }

    public function test_an_owner_adds_an_existing_user_by_email(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create(['email' => 'guest@example.com']);
        $workspace = $this->workspaceWith($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'guest@example.com', 'role' => 'editor'])
            ->assertCreated()
            ->assertJsonPath('email', 'guest@example.com')
            ->assertJsonPath('role', 'editor');

        $this->assertSame(WorkspaceRole::Editor, $guest->roleIn($workspace));
    }

    public function test_adding_someone_without_an_account_says_so(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWith($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'ghost@example.com', 'role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_adding_someone_twice_is_rejected(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $workspace = $this->workspaceWith($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'owner@example.com', 'role' => 'viewer'])
            ->assertUnprocessable();

        $this->assertSame(WorkspaceRole::Owner, $owner->roleIn($workspace));
    }

    public function test_a_role_outside_the_three_is_rejected(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'guest@example.com']);
        $workspace = $this->workspaceWith($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'guest@example.com', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_only_an_owner_manages_members(): void
    {
        $editor = User::factory()->create();
        $other = User::factory()->create(['email' => 'other@example.com']);
        $workspace = $this->workspaceWith(User::factory()->create());
        $workspace->members()->attach($editor, ['role' => 'editor']);
        $workspace->members()->attach($other, ['role' => 'viewer']);

        $this->actingAs($editor, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertForbidden();
        $this->actingAs($editor, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$other->id}", ['role' => 'editor'])
            ->assertForbidden();
        $this->actingAs($editor, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$other->id}")
            ->assertForbidden();

        $this->assertSame(WorkspaceRole::Viewer, $other->roleIn($workspace));
    }

    public function test_an_owner_changes_a_members_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceWith($owner);
        $workspace->members()->attach($member, ['role' => 'viewer']);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$member->id}", ['role' => 'editor'])
            ->assertOk()->assertJsonPath('role', 'editor');

        $this->assertSame(WorkspaceRole::Editor, $member->roleIn($workspace));
    }

    public function test_an_owner_removes_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceWith($owner);
        $workspace->members()->attach($member, ['role' => 'editor']);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$member->id}")->assertNoContent();

        $this->assertNull($member->roleIn($workspace));
    }

    public function test_a_member_can_leave_on_their_own(): void
    {
        $member = User::factory()->create();
        $workspace = $this->workspaceWith(User::factory()->create());
        $workspace->members()->attach($member, ['role' => 'viewer']);

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$member->id}")->assertNoContent();

        $this->assertNull($member->roleIn($workspace));
    }

    public function test_the_last_owner_cannot_leave_be_removed_or_be_demoted(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWith($owner);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$owner->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$owner->id}", ['role' => 'editor'])
            ->assertUnprocessable();

        $this->assertSame(WorkspaceRole::Owner, $owner->roleIn($workspace));
    }

    public function test_with_a_second_owner_the_first_may_step_down(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $workspace = $this->workspaceWith($first);
        $workspace->members()->attach($second, ['role' => 'owner']);

        $this->actingAs($first, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$first->id}", ['role' => 'viewer'])
            ->assertOk();

        $this->assertSame(WorkspaceRole::Viewer, $first->roleIn($workspace));
        $this->assertSame(1, $workspace->ownerCount());
    }

    public function test_targeting_someone_who_is_not_a_member_is_a_404(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWith($owner);
        $stranger = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$stranger->id}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$stranger->id}", ['role' => 'viewer'])->assertNotFound();
    }

    public function test_the_demo_account_cannot_leave_its_own_workspace(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();
        $workspace = $demo->defaultWorkspace();

        $this->actingAs($demo, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$demo->id}")->assertForbidden();

        $this->assertSame(WorkspaceRole::Owner, $demo->roleIn($workspace));
    }
}
