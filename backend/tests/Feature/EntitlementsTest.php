<?php

namespace Tests\Feature;

use App\Billing\Entitlements;
use App\Billing\LimitedResource;
use App\Billing\PlanCatalog;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithBilling;
use Tests\TestCase;

/**
 * Who has paid for what, turned into a plan. Every case here is a way a
 * workspace could end up with too much or too little.
 */
class EntitlementsTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBilling();
    }

    private function workspace(): Workspace
    {
        return Workspace::factory()->create();
    }

    private function plan(Workspace $workspace): string
    {
        return Entitlements::for($workspace->fresh())->plan()->key;
    }

    public function test_a_workspace_with_nothing_is_on_the_free_plan(): void
    {
        $workspace = $this->workspace();
        $entitlements = Entitlements::for($workspace);

        $this->assertSame('free', $entitlements->plan()->key);
        $this->assertSame('free', $entitlements->source());
        $this->assertNull($entitlements->subscription());
        $this->assertSame(3, $entitlements->limit(LimitedResource::Links));
    }

    public function test_a_live_subscription_gives_the_plan_its_price_buys(): void
    {
        $workspace = $this->workspace();
        $this->subscribe(User::factory()->create(), $workspace, 'pri_team_m');

        $entitlements = Entitlements::for($workspace);

        $this->assertSame('team', $entitlements->plan()->key);
        $this->assertSame('subscription', $entitlements->source());
        $this->assertNull($entitlements->limit(LimitedResource::Links), 'a null ceiling means unlimited');
    }

    public function test_the_yearly_price_buys_the_same_plan_as_the_monthly_one(): void
    {
        $workspace = $this->workspace();
        $this->subscribe(User::factory()->create(), $workspace, 'pri_pro_y');

        $this->assertSame('pro', $this->plan($workspace));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function subscriptionStates(): array
    {
        return [
            'active' => ['active', [], 'pro'],
            'in a trial' => ['trialing', [], 'pro'],
            // Paddle retries a declined card for days; the plan is not taken
            // away on the first failure.
            'payment failed, still being retried' => ['past_due', [], 'pro'],
            // Cancelled from the customer's side, but paid to the end of the
            // period: Paddle keeps it active until then.
            'cancelled but paid until later' => ['active', ['ends_at' => '+10 days'], 'pro'],
            'cancelled and over' => ['canceled', ['ends_at' => '-1 day'], 'free'],
            'paused' => ['paused', [], 'free'],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    #[DataProvider('subscriptionStates')]
    public function test_only_a_subscription_in_force_counts(string $status, array $attributes, string $expected): void
    {
        $workspace = $this->workspace();

        if (isset($attributes['ends_at'])) {
            $attributes['ends_at'] = now()->modify($attributes['ends_at']);
        }

        $this->subscribe(User::factory()->create(), $workspace, 'pri_pro_m', $status, $attributes);

        $this->assertSame($expected, $this->plan($workspace));
    }

    public function test_a_price_we_do_not_sell_buys_nothing(): void
    {
        $workspace = $this->workspace();
        $this->subscribe(User::factory()->create(), $workspace, 'pri_from_another_product');

        $this->assertSame('free', $this->plan($workspace));
    }

    public function test_one_workspaces_subscription_does_not_entitle_another(): void
    {
        $payer = User::factory()->create();
        $paid = $this->workspace();
        $other = $this->workspace();
        $this->subscribe($payer, $paid, 'pri_team_m');

        // The same person pays for both of them in real life; only one is paid.
        $this->assertSame('team', $this->plan($paid));
        $this->assertSame('free', $this->plan($other));
    }

    public function test_a_workspace_id_that_merely_starts_like_another_does_not_match(): void
    {
        $workspace = $this->workspace();
        $payer = User::factory()->create();

        // "workspace:1" must not pick up "workspace:10" or "workspace:100".
        foreach ([$workspace->id.'0', $workspace->id.'00'] as $lookalike) {
            $this->subscribe($payer, $workspace, 'pri_team_m', 'active', ['type' => "workspace:{$lookalike}"]);
        }

        $this->assertSame('free', $this->plan($workspace));
    }

    public function test_a_granted_plan_is_used_without_any_subscription(): void
    {
        $workspace = $this->workspace();
        $workspace->forceFill(['granted_plan' => 'pro'])->save();

        $entitlements = Entitlements::for($workspace);

        $this->assertSame('pro', $entitlements->plan()->key);
        $this->assertSame('granted', $entitlements->source());
    }

    public function test_a_granted_plan_that_no_longer_exists_is_ignored(): void
    {
        $workspace = $this->workspace();
        $workspace->forceFill(['granted_plan' => 'platinum'])->save();

        $this->assertSame('free', $this->plan($workspace));
    }

    public function test_the_better_of_a_gift_and_a_purchase_wins(): void
    {
        $workspace = $this->workspace();
        $workspace->forceFill(['granted_plan' => 'team'])->save();
        $this->subscribe(User::factory()->create(), $workspace, 'pri_pro_m');

        $entitlements = Entitlements::for($workspace->fresh());

        $this->assertSame('team', $entitlements->plan()->key);
        $this->assertSame('granted', $entitlements->source());
        $this->assertNotNull($entitlements->subscription(), 'the owner must still be able to see and manage what they pay for');
    }

    public function test_on_a_tie_the_purchase_is_what_is_reported(): void
    {
        $workspace = $this->workspace();
        $workspace->forceFill(['granted_plan' => 'pro'])->save();
        $this->subscribe(User::factory()->create(), $workspace, 'pri_pro_m');

        $this->assertSame('subscription', Entitlements::for($workspace->fresh())->source());
    }

    public function test_with_two_subscriptions_the_better_one_counts(): void
    {
        $workspace = $this->workspace();
        $payer = User::factory()->create();
        $this->subscribe($payer, $workspace, 'pri_team_m');
        $this->subscribe($payer, $workspace, 'pri_pro_m');

        $this->assertSame('team', $this->plan($workspace));
    }

    public function test_a_self_hosted_install_has_no_ceiling_at_all(): void
    {
        config(['billing.enabled' => false]);
        $workspace = $this->workspace();

        $entitlements = Entitlements::for($workspace);

        $this->assertSame(PlanCatalog::SELF_HOSTED, $entitlements->plan()->key);
        foreach (LimitedResource::cases() as $resource) {
            $this->assertNull($entitlements->limit($resource));
        }
    }

    public function test_usage_counts_links_domains_and_people_of_that_workspace_only(): void
    {
        $owner = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();
        Link::factory()->count(2)->for($site)->create();
        Domain::factory()->for($site)->create();
        Link::factory()->count(5)->for(Site::factory()->create())->create(); // someone else's

        $entitlements = Entitlements::for($site->workspace);

        $this->assertSame(2, $entitlements->usage(LimitedResource::Links));
        $this->assertSame(1, $entitlements->usage(LimitedResource::Domains));
        $this->assertSame(1, $entitlements->usage(LimitedResource::Members));
    }
}
