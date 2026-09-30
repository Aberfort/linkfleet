<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\InteractsWithBilling;
use Tests\TestCase;

/**
 * A plan sells room. These are the places a workspace could grow, and the
 * promise that going over never takes anything away.
 */
class PlanLimitsTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBilling();
    }

    private function createLink(User $user, Site $site, string $target = 'https://example.com/x')
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/sites/{$site->id}/links", ['target_url' => $target]);
    }

    public function test_a_free_workspace_can_add_links_up_to_its_limit_and_not_one_more(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        foreach (range(1, 3) as $i) {
            $this->createLink($user, $site)->assertCreated();
        }

        $this->createLink($user, $site)
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit')
            ->assertJsonPath('resource', 'links')
            ->assertJsonPath('limit', 3)
            ->assertJsonPath('usage', 3)
            ->assertJsonPath('plan', 'free')
            ->assertJsonPath('workspace_id', $site->workspace_id);

        $this->assertSame(3, Link::count());
    }

    public function test_the_message_names_the_plan_and_the_ceiling(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(3)->for($site)->create();

        $message = $this->createLink($user, $site)->assertStatus(402)->json('message');

        $this->assertStringContainsString('Free', $message);
        $this->assertStringContainsString('посилань', $message);
        $this->assertStringContainsString('3', $message);
    }

    public function test_the_limit_is_for_the_whole_workspace_not_for_each_site(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();
        $one = Site::factory()->create(['workspace_id' => $workspace->id]);
        $two = Site::factory()->create(['workspace_id' => $workspace->id]);
        Link::factory()->count(2)->for($one)->create();
        Link::factory()->count(1)->for($two)->create();

        $this->createLink($user, $one)->assertStatus(402);
        $this->createLink($user, $two)->assertStatus(402);
    }

    public function test_links_in_another_workspace_use_up_nothing(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(20)->for(Site::factory()->create())->create();

        $this->createLink($user, $site)->assertCreated();
    }

    public function test_deleting_a_link_frees_its_room(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $links = Link::factory()->count(3)->for($site)->create();
        $this->createLink($user, $site)->assertStatus(402);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/links/{$links->first()->id}")->assertNoContent();

        $this->createLink($user, $site)->assertCreated();
    }

    public function test_a_paid_plan_raises_the_ceiling(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(3)->for($site)->create();
        $this->subscribe($user, $site->workspace, 'pri_pro_m');

        $this->createLink($user, $site)->assertCreated();
    }

    public function test_a_plan_with_no_ceiling_never_refuses(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(40)->for($site)->create();
        $this->subscribe($user, $site->workspace, 'pri_team_m');

        $this->createLink($user, $site)->assertCreated();
    }

    public function test_an_api_key_is_held_to_the_same_limit(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(3)->for($site)->create();
        $key = $user->createToken('CI', ['read', 'write'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($key)
            ->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com/x'])
            ->assertStatus(402);
    }

    public function test_being_over_the_limit_takes_nothing_away(): void
    {
        // A workspace can get here by downgrading. Its links must keep
        // working: redirecting, listing, editing, switching off.
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $links = Link::factory()->count(6)->for($site)->create(['is_active' => true]);
        $link = $links->first();

        $this->get("/r/{$link->short_code}")->assertRedirect($link->target_url);
        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/links")->assertOk()->assertJsonCount(6);
        $this->actingAs($user, 'sanctum')->putJson("/api/links/{$link->id}", ['target_url' => 'https://example.com/new'])->assertOk();
        $this->actingAs($user, 'sanctum')->patchJson("/api/links/{$link->id}/toggle")->assertOk();

        $this->createLink($user, $site)->assertStatus(402)->assertJsonPath('usage', 6);
    }

    public function test_billing_off_means_no_limit_whatever_the_plans_say(): void
    {
        config(['billing.enabled' => false]);
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(30)->for($site)->create();

        $this->createLink($user, $site)->assertCreated();
    }

    public function test_an_import_takes_rows_until_the_plan_is_full_then_says_so(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->count(1)->for($site)->create(); // room for two more

        $csv = "target_url\nhttps://example.com/1\nhttps://example.com/2\nhttps://example.com/3\nhttps://example.com/4\n";

        $this->actingAs($user, 'sanctum')
            ->post("/api/sites/{$site->id}/links/import", ['file' => UploadedFile::fake()->createWithContent('links.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('plan_limit', true)
            ->assertJsonPath('skipped.0.row', 4)
            ->assertJsonCount(1, 'skipped'); // it stops; it does not list every remaining row

        $this->assertSame(3, Link::count());
        $this->assertDatabaseMissing('links', ['target_url' => 'https://example.com/3']);
    }

    public function test_an_import_that_fits_reports_no_limit(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'sanctum')
            ->post("/api/sites/{$site->id}/links/import", ['file' => UploadedFile::fake()->createWithContent('links.csv', "target_url\nhttps://example.com/1\n")])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('plan_limit', false);
    }

    public function test_the_free_plan_has_no_custom_domains(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$site->id}/domain", ['host' => 'go.example.com'])
            ->assertStatus(402)
            ->assertJsonPath('resource', 'domains')
            ->assertJsonPath('limit', 0);

        $this->assertDatabaseCount('domains', 0);
    }

    public function test_domains_are_counted_across_the_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();
        $this->subscribe($user, $workspace, 'pri_pro_m'); // two domains
        $sites = Site::factory()->count(3)->create(['workspace_id' => $workspace->id]);

        foreach ($sites->take(2) as $i => $site) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/sites/{$site->id}/domain", ['host' => "go{$i}.example.com"])
                ->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$sites[2]->id}/domain", ['host' => 'go9.example.com'])
            ->assertStatus(402)
            ->assertJsonPath('usage', 2);
    }

    public function test_swapping_a_sites_domain_is_allowed_when_the_plan_is_full(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();
        $this->subscribe($user, $workspace, 'pri_pro_m');
        $first = Site::factory()->create(['workspace_id' => $workspace->id]);
        $second = Site::factory()->create(['workspace_id' => $workspace->id]);
        Domain::factory()->for($first)->create(['host' => 'old.example.com']);
        Domain::factory()->for($second)->create(['host' => 'other.example.com']);

        // Full (2 of 2) - but replacing one adds nothing.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$first->id}/domain", ['host' => 'new.example.com'])
            ->assertCreated();

        $this->assertDatabaseMissing('domains', ['host' => 'old.example.com']);
        $this->assertDatabaseCount('domains', 2);
    }

    public function test_a_refused_domain_changes_nothing(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();
        $site = Site::factory()->create(['workspace_id' => $workspace->id]);
        $other = Site::factory()->create(['workspace_id' => $workspace->id]);
        $this->subscribe($user, $workspace, 'pri_pro_m');
        Domain::factory()->for($site)->create(['host' => 'keep.example.com']);
        Domain::factory()->for($other)->create(['host' => 'keep2.example.com']);
        $third = Site::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$third->id}/domain", ['host' => 'nope.example.com'])
            ->assertStatus(402);

        $this->assertDatabaseHas('domains', ['host' => 'keep.example.com']);
        $this->assertDatabaseHas('domains', ['host' => 'keep2.example.com']);
    }

    public function test_the_free_plan_is_one_person(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'guest@example.com']);
        $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'guest@example.com', 'role' => 'viewer'])
            ->assertStatus(402)
            ->assertJsonPath('resource', 'members')
            ->assertJsonPath('limit', 1);

        $this->assertSame(1, $workspace->members()->count());
    }

    public function test_a_paid_plan_makes_room_for_more_people(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'a@example.com']);
        User::factory()->create(['email' => 'b@example.com']);
        User::factory()->create(['email' => 'c@example.com']);
        $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();
        $this->subscribe($owner, $workspace, 'pri_pro_m'); // three people

        foreach (['a', 'b'] as $who) {
            $this->actingAs($owner, 'sanctum')
                ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => "{$who}@example.com", 'role' => 'viewer'])
                ->assertCreated();
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'c@example.com', 'role' => 'viewer'])
            ->assertStatus(402);
    }

    public function test_a_mistake_in_the_request_is_reported_before_the_limit(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();

        // Full for members - but nobody by that email exists, and that is what is wrong.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/members", ['email' => 'ghost@example.com', 'role' => 'viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_workspaces_can_still_be_created_for_free(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', ['name' => 'Another'])->assertCreated();
    }
}
