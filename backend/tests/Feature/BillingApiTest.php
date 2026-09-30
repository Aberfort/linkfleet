<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithBilling;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBilling();
    }

    /** @return array{0: User, 1: Workspace} */
    private function ownerWithWorkspace(): array
    {
        $owner = User::factory()->create();

        return [$owner, $owner->defaultWorkspace()];
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    // ---- reading the plan -------------------------------------------------

    public function test_an_owner_sees_the_plan_the_limits_and_what_is_used(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Link::factory()->count(2)->for(Site::factory()->create(['workspace_id' => $workspace->id]))->create();

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertOk()
            ->assertJsonPath('plan.key', 'free')
            ->assertJsonPath('plan.name', 'Free')
            ->assertJsonPath('source', 'free')
            ->assertJsonPath('limits', ['links' => 3, 'domains' => 0, 'members' => 1])
            ->assertJsonPath('usage', ['links' => 2, 'domains' => 0, 'members' => 1])
            ->assertJsonPath('over_limit', [])
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('subscription', null);
    }

    public function test_an_unlimited_resource_is_reported_as_null(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_team_m');

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertOk()
            ->assertJsonPath('plan.key', 'team')
            ->assertJsonPath('limits.links', null);
    }

    public function test_a_member_who_is_not_an_owner_can_look_but_not_manage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
        $this->subscribe($owner, $workspace);

        $this->as($viewer)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertOk()
            ->assertJsonPath('plan.key', 'pro')
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('subscription.payer_name', null); // who pays is for the people who could act on it
    }

    public function test_a_stranger_cannot_see_a_workspaces_billing(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $this->as(User::factory()->create())->getJson("/api/workspaces/{$workspace->id}/billing")->assertForbidden();
    }

    public function test_the_subscription_shows_who_pays_and_whether_it_is_the_caller(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $second = User::factory()->create(['name' => 'Second Owner']);
        $workspace->members()->attach($second, ['role' => WorkspaceRole::Owner->value]);
        $this->subscribe($owner, $workspace, 'pri_pro_y');

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertJsonPath('subscription.is_payer', true)
            ->assertJsonPath('subscription.plan', 'pro')
            ->assertJsonPath('subscription.interval', 'yearly')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.payer_name', $owner->name);

        $this->as($second)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('subscription.is_payer', false)
            ->assertJsonPath('subscription.payer_name', $owner->name);
    }

    public function test_a_cancelled_subscription_reports_when_it_ends(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m', 'active', ['ends_at' => now()->addDays(9)]);

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertJsonPath('subscription.on_grace_period', true)
            ->assertJsonPath('subscription.past_due', false)
            ->assertJsonStructure(['subscription' => ['ends_at']]);
    }

    public function test_a_failed_payment_is_flagged(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m', 'past_due');

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertJsonPath('plan.key', 'pro')
            ->assertJsonPath('subscription.past_due', true);
    }

    public function test_a_workspace_that_slipped_over_its_limits_says_which(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Link::factory()->count(5)->for(Site::factory()->create(['workspace_id' => $workspace->id]))->create();

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertJsonPath('over_limit', ['links']);
    }

    public function test_the_demo_account_can_look_but_not_manage(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);
        $workspace = $demo->defaultWorkspace();

        $this->as($demo)->getJson("/api/workspaces/{$workspace->id}/billing")
            ->assertOk()
            ->assertJsonPath('can_manage', false);
    }

    public function test_an_api_key_may_read_the_plan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $key = $owner->createToken('CI', ['read'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($key)->getJson("/api/workspaces/{$workspace->id}/billing")->assertOk();
    }

    public function test_none_of_it_exists_on_a_self_hosted_install(): void
    {
        config(['billing.enabled' => false]);
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing")->assertNotFound();
        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertNotFound();
        $this->getJson('/api/plans')->assertNotFound();
    }

    // ---- buying -----------------------------------------------------------

    public function test_checkout_hands_the_browser_everything_paddle_needs_and_nothing_it_could_abuse(): void
    {
        $this->fakePaddleApi();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $options = $this->as($owner)
            ->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])
            ->assertOk()
            ->json('checkout');

        $this->assertSame('pri_pro_m', $options['items'][0]['priceId']);
        $this->assertSame(1, $options['items'][0]['quantity']);
        $this->assertSame($this->customerFor($owner)->paddle_id, $options['customer']['id']);
        // The subscription is filed under the workspace, by the server.
        $this->assertSame("workspace:{$workspace->id}", $options['customData']['subscription_type']);
        $this->assertStringNotContainsString('test_api_key', json_encode($options));
    }

    public function test_the_paddle_customer_is_made_once_per_person_however_many_workspaces_they_pay_for(): void
    {
        $this->fakePaddleApi();
        [$owner, $first] = $this->ownerWithWorkspace();
        $second = Workspace::factory()->withMember($owner, WorkspaceRole::Owner)->create();

        $one = $this->as($owner)->postJson("/api/workspaces/{$first->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertOk();
        $two = $this->as($owner)->postJson("/api/workspaces/{$second->id}/billing/checkout", ['price_id' => 'pri_team_m'])->assertOk();

        $this->assertSame($one->json('checkout.customer.id'), $two->json('checkout.customer.id'));
        $this->assertSame("workspace:{$second->id}", $two->json('checkout.customData.subscription_type'));
        Http::assertSentCount(2); // one lookup + one create, not once per checkout
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_the_customer_is_created_with_the_payers_email(): void
    {
        $this->fakePaddleApi();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/customers')
            && $request['email'] === $owner->email);
    }

    public function test_only_prices_we_sell_can_be_checked_out(): void
    {
        $this->fakePaddleApi();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        foreach (['pri_someone_elses', '', null] as $price) {
            $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => $price])
                ->assertUnprocessable()->assertJsonValidationErrors('price_id');
        }

        Http::assertNothingSent();
    }

    public function test_only_owners_can_start_a_checkout(): void
    {
        $this->fakePaddleApi();
        [, $workspace] = $this->ownerWithWorkspace();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $workspace->members()->attach($editor, ['role' => WorkspaceRole::Editor->value]);
        $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

        foreach ([$editor, $viewer, User::factory()->create()] as $user) {
            $this->as($user)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_the_demo_account_cannot_buy_anything(): void
    {
        $this->fakePaddleApi();
        $demo = User::factory()->create(['is_demo' => true]);
        $workspace = $demo->defaultWorkspace();

        $this->as($demo)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_an_api_key_cannot_spend_money(): void
    {
        $this->fakePaddleApi();
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $key = $owner->createToken('CI', ['read', 'write'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($key)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_second_subscription_for_the_same_workspace_is_refused(): void
    {
        $this->fakePaddleApi();
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_team_m'])
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_server_says_so_rather_than_failing_on_the_first_customer(): void
    {
        config(['cashier.api_key' => null]);
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])
            ->assertStatus(503);
    }

    public function test_when_paddle_refuses_the_customer_is_told_it_is_not_their_fault(): void
    {
        Http::fake([
            'sandbox-api.paddle.com/customers*' => Http::response(['error' => ['type' => 'request_error', 'code' => 'forbidden', 'detail' => 'Not allowed for this key']], 403),
        ]);
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $response = $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])
            ->assertStatus(502);

        // The customer sees a plain sentence; Paddle's own words stay in the log.
        $this->assertStringNotContainsString('Not allowed', $response->getContent());
    }

    public function test_when_paddle_cannot_be_reached_the_answer_is_the_same(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/checkout", ['price_id' => 'pri_pro_m'])
            ->assertStatus(502);
    }

    // ---- changing ---------------------------------------------------------

    private function paddleAnswers(string $subscriptionId, string $priceId, string $status = 'active', array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'items' => [['status' => 'active', 'quantity' => 1, 'price' => ['id' => $priceId, 'product_id' => 'pro_01']]],
        ], $extra);
    }

    public function test_the_payer_can_move_to_another_plan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace, 'pri_pro_m');
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}" => Http::response([
            'data' => $this->paddleAnswers($subscription->paddle_id, 'pri_team_m'),
        ])]);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_team_m'])
            ->assertOk()
            ->assertJsonPath('plan.key', 'team')
            ->assertJsonPath('subscription.plan', 'team');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request['items'][0]['price_id'] === 'pri_team_m');
    }

    public function test_someone_who_does_not_pay_cannot_change_the_plan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $second = User::factory()->create();
        $workspace->members()->attach($second, ['role' => WorkspaceRole::Owner->value]);
        $this->subscribe($owner, $workspace, 'pri_pro_m');
        Http::fake();

        $this->as($second)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_team_m'])->assertForbidden();
        $this->as($second)->postJson("/api/workspaces/{$workspace->id}/billing/cancel")->assertForbidden();
        $this->as($second)->getJson("/api/workspaces/{$workspace->id}/billing/payment-method")->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_changing_when_there_is_nothing_to_change_is_a_404(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Http::fake();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_team_m'])->assertNotFound();
        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/cancel")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_moving_to_the_plan_already_held_is_refused(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m');
        Http::fake();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_pro_m'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_a_plan_cannot_be_changed_while_a_payment_is_failing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m', 'past_due');
        Http::fake();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_team_m'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_moving_to_a_smaller_plan_is_allowed_and_takes_nothing_away(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace, 'pri_team_m');
        Link::factory()->count(6)->for(Site::factory()->create(['workspace_id' => $workspace->id]))->create();
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}" => Http::response([
            'data' => $this->paddleAnswers($subscription->paddle_id, 'pri_pro_m'),
        ])]);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/change", ['price_id' => 'pri_pro_m'])
            ->assertOk()
            ->assertJsonPath('plan.key', 'pro')
            ->assertJsonPath('over_limit', []); // 6 of 10 - fine

        $this->assertSame(6, Link::count());
    }

    // ---- ending -----------------------------------------------------------

    public function test_cancelling_ends_the_plan_at_the_end_of_the_period_paid_for(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace);
        $effective = now()->addDays(20)->utc()->format('Y-m-d\TH:i:s.u\Z');
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}/cancel" => Http::response([
            'data' => ['status' => 'active', 'canceled_at' => null, 'scheduled_change' => ['action' => 'cancel', 'effective_at' => $effective]],
        ])]);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/cancel")
            ->assertOk()
            ->assertJsonPath('plan.key', 'pro') // still paid for
            ->assertJsonPath('subscription.on_grace_period', true);

        Http::assertSent(fn (Request $request) => $request['effective_from'] === 'next_billing_period');
    }

    public function test_cancelling_twice_asks_paddle_only_once(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m', 'active', ['ends_at' => now()->addDays(5)]);
        Http::fake();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/cancel")
            ->assertOk()->assertJsonPath('subscription.on_grace_period', true);

        Http::assertNothingSent();
    }

    public function test_a_cancellation_can_be_taken_back(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace, 'pri_pro_m', 'active', ['ends_at' => now()->addDays(5)]);
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}" => Http::response([
            'data' => $this->paddleAnswers($subscription->paddle_id, 'pri_pro_m'),
        ])]);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/resume")
            ->assertOk()
            ->assertJsonPath('subscription.on_grace_period', false)
            ->assertJsonPath('subscription.ends_at', null);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && array_key_exists('scheduled_change', $request->data()) && $request['scheduled_change'] === null);
    }

    public function test_there_is_nothing_to_take_back_on_a_subscription_that_is_not_cancelled(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace);
        Http::fake();

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/resume")->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_the_payer_is_sent_to_paddles_page_for_changing_the_card(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace);
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}" => Http::response([
            'data' => ['management_urls' => ['update_payment_method' => 'https://checkout.paddle.test/update', 'cancel' => 'https://checkout.paddle.test/cancel']],
        ])]);

        $this->as($owner)->getJson("/api/workspaces/{$workspace->id}/billing/payment-method")
            ->assertOk()
            ->assertExactJson(['url' => 'https://checkout.paddle.test/update']);
    }

    public function test_paddle_failing_while_cancelling_changes_nothing_here(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $subscription = $this->subscribe($owner, $workspace);
        Http::fake(["sandbox-api.paddle.com/subscriptions/{$subscription->paddle_id}/cancel" => Http::response([
            'error' => ['type' => 'request_error', 'code' => 'conflict', 'detail' => 'nope'],
        ], 409)]);

        $this->as($owner)->postJson("/api/workspaces/{$workspace->id}/billing/cancel")->assertStatus(502);

        $this->assertNull($subscription->fresh()->ends_at);
    }

    // ---- deleting a workspace --------------------------------------------

    public function test_a_workspace_that_is_still_being_billed_cannot_be_deleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace);

        $this->as($owner)->deleteJson("/api/workspaces/{$workspace->id}")->assertStatus(409);

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_one_whose_subscription_is_already_ending_can_be_deleted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->subscribe($owner, $workspace, 'pri_pro_m', 'active', ['ends_at' => now()->addDays(3)]);

        $this->as($owner)->deleteJson("/api/workspaces/{$workspace->id}")->assertNoContent();
    }

    public function test_a_free_workspace_deletes_as_ever(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->deleteJson("/api/workspaces/{$workspace->id}")->assertNoContent();
    }

    public function test_deleting_is_unaffected_where_nothing_is_sold(): void
    {
        config(['billing.enabled' => false]);
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->as($owner)->deleteJson("/api/workspaces/{$workspace->id}")->assertNoContent();
    }

    // ---- public ----------------------------------------------------------

    public function test_the_price_list_is_public_and_names_what_each_plan_holds(): void
    {
        $response = $this->getJson('/api/plans')->assertOk();

        $this->assertSame(['free', 'pro', 'team'], collect($response->json('plans'))->pluck('key')->all());
        $response->assertJsonPath('plans.0.limits', ['links' => 3, 'domains' => 0, 'members' => 1])
            ->assertJsonPath('plans.1.prices', ['monthly' => 'pri_pro_m', 'yearly' => 'pri_pro_y'])
            ->assertJsonPath('plans.2.limits.links', null)
            ->assertJsonPath('checkout_available', true);
    }

    public function test_free_has_an_empty_set_of_prices_not_a_missing_one(): void
    {
        $free = $this->getJson('/api/plans')->json('plans.0');

        $this->assertSame([], $free['prices']);
    }

    public function test_the_price_list_says_when_nothing_can_be_bought_yet(): void
    {
        config(['cashier.webhook_secret' => null]);

        $this->getJson('/api/plans')->assertOk()->assertJsonPath('checkout_available', false);
    }

    public function test_the_config_tells_the_browser_what_paddlejs_needs(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('billing.enabled', true)
            ->assertJsonPath('billing.client_side_token', 'test_client_token')
            ->assertJsonPath('billing.sandbox', true);
    }

    public function test_the_config_of_a_self_hosted_install_carries_no_paddle_keys(): void
    {
        config(['billing.enabled' => false]);

        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('billing.enabled', false)
            ->assertJsonMissingPath('billing.client_side_token')
            ->assertJsonMissingPath('billing.sandbox');
    }

    public function test_the_secret_keys_never_appear_in_any_public_answer(): void
    {
        $everything = $this->getJson('/api/config')->getContent().$this->getJson('/api/plans')->getContent();

        $this->assertStringNotContainsString('test_api_key', $everything);
        $this->assertStringNotContainsString($this->webhookSecret, $everything);
    }
}
