<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What each role may do to the things inside a workspace, in one place -
 * the table in the README is checked here rather than trusted.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Site, 2: Link} */
    private function scene(?WorkspaceRole $role): array
    {
        $user = User::factory()->create();
        $site = $role
            ? Site::factory()->sharedWith($user, $role)->create()
            : Site::factory()->create();

        return [$user, $site, Link::factory()->for($site)->create(['short_code' => 'promo'])];
    }

    /** role => [may read, may edit links, may manage domain] */
    public static function roles(): array
    {
        return [
            'owner' => [WorkspaceRole::Owner, true, true, true],
            'editor' => [WorkspaceRole::Editor, true, true, false],
            'viewer' => [WorkspaceRole::Viewer, true, false, false],
            'outsider' => [null, false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_reading_links_and_analytics(?WorkspaceRole $role, bool $read): void
    {
        [$user, $site, $link] = $this->scene($role);
        $as = fn () => $this->actingAs($user, 'sanctum');

        $expected = $read ? 200 : 403;
        $as()->getJson("/api/sites/{$site->id}/links")->assertStatus($expected);
        $as()->getJson("/api/links/{$link->id}")->assertStatus($expected);
        $as()->getJson("/api/sites/{$site->id}/analytics")->assertStatus($expected);
        $as()->getJson("/api/links/{$link->id}/analytics")->assertStatus($expected);
    }

    #[DataProvider('roles')]
    public function test_changing_links(?WorkspaceRole $role, bool $read, bool $edit): void
    {
        [$user, $site, $link] = $this->scene($role);
        $as = fn () => $this->actingAs($user, 'sanctum');
        $deny = 403;

        $as()->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com/new'])
            ->assertStatus($edit ? 201 : $deny);
        $as()->putJson("/api/links/{$link->id}", ['target_url' => 'https://example.com/changed'])
            ->assertStatus($edit ? 200 : $deny);
        $as()->patchJson("/api/links/{$link->id}/toggle")->assertStatus($edit ? 200 : $deny);
        $as()->deleteJson("/api/links/{$link->id}")->assertStatus($edit ? 204 : $deny);
    }

    #[DataProvider('roles')]
    public function test_domains_are_owner_only_to_change_but_readable_by_members(
        ?WorkspaceRole $role, bool $read, bool $edit, bool $owner
    ): void {
        [$user, $site] = $this->scene($role);
        $domain = Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        $as = fn () => $this->actingAs($user, 'sanctum');

        $as()->getJson("/api/sites/{$site->id}/domain")->assertStatus($read ? 200 : 403);
        $as()->postJson("/api/domains/{$domain->id}/check")->assertStatus($read ? 200 : 403);

        $as()->postJson("/api/sites/{$site->id}/domain", ['host' => 'new.example.com'])
            ->assertStatus($owner ? 201 : 403);
        // The POST above replaced the domain for an owner, so re-read it.
        $current = $site->fresh()->customDomain ?? $domain;
        $as()->deleteJson("/api/domains/{$current->id}")->assertStatus($owner ? 204 : 403);
    }

    public function test_sharing_a_workspace_does_not_leak_other_workspaces(): void
    {
        $user = User::factory()->create();
        $shared = Site::factory()->sharedWith($user, WorkspaceRole::Editor)->create();
        $private = Site::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$shared->id}")->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$private->id}")->assertForbidden();
    }
}
