<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Site, 2: Link, 3: Click} */
    private function scene(bool $tracking = true, WorkspaceRole $role = WorkspaceRole::Owner): array
    {
        $user = User::factory()->create();
        $site = Site::factory()->sharedWith($user, $role)->create(['conversion_tracking' => $tracking]);
        $link = Link::factory()->for($site)->create();

        return [$user, $site, $link, Click::factory()->create(['link_id' => $link->id])];
    }

    private function report(User $user, Click $click, array $overrides = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/conversions', $overrides + [
            'click_id' => $click->token,
            'event' => 'purchase',
        ]);
    }

    public function test_a_conversion_is_recorded_against_its_click_and_link(): void
    {
        [$user, , $link, $click] = $this->scene();

        $this->report($user, $click, ['value' => '49.90', 'currency' => 'usd', 'external_id' => 'order-1001'])
            ->assertCreated()
            ->assertJsonPath('event', 'purchase')
            ->assertJsonPath('value', '49.90')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('external_id', 'order-1001')
            ->assertJsonPath('source', 'server')
            ->assertJsonPath('duplicate', false);

        $conversion = Conversion::sole();
        $this->assertSame($click->id, $conversion->click_id);
        $this->assertSame($link->id, $conversion->link_id);
    }

    public function test_an_event_with_no_money_needs_neither_value_nor_currency(): void
    {
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['event' => 'signup'])->assertCreated()
            ->assertJsonPath('value', null)->assertJsonPath('currency', null)->assertJsonPath('external_id', null);
    }

    public function test_event_and_currency_are_tidied_rather_than_rejected(): void
    {
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['event' => '  Trial.Started ', 'value' => '0', 'currency' => ' eur '])
            ->assertCreated()->assertJsonPath('event', 'trial.started')->assertJsonPath('currency', 'EUR');
    }

    public function test_sending_the_same_conversion_twice_records_it_once(): void
    {
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['external_id' => 'order-1', 'value' => '10', 'currency' => 'USD'])->assertCreated();
        $this->report($user, $click, ['external_id' => 'order-1', 'value' => '10', 'currency' => 'USD'])
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Conversion::count());
    }

    public function test_a_repeat_without_an_id_is_still_the_same_conversion(): void
    {
        // A NULL in a unique index would make every retry look new.
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['event' => 'signup'])->assertCreated();
        $this->report($user, $click, ['event' => 'signup'])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Conversion::count());
    }

    public function test_one_click_can_lead_to_several_orders_and_several_kinds_of_event(): void
    {
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['event' => 'purchase', 'external_id' => 'order-1'])->assertCreated();
        $this->report($user, $click, ['event' => 'purchase', 'external_id' => 'order-2'])->assertCreated();
        $this->report($user, $click, ['event' => 'signup'])->assertCreated();

        $this->assertSame(3, Conversion::count());
    }

    public function test_the_same_order_id_on_a_different_click_is_a_different_conversion(): void
    {
        [$user, , $link, $click] = $this->scene();
        $other = Click::factory()->create(['link_id' => $link->id]);

        $this->report($user, $click, ['external_id' => 'order-1'])->assertCreated();
        $this->report($user, $other, ['external_id' => 'order-1'])->assertCreated();

        $this->assertSame(2, Conversion::count());
    }

    public function test_a_retry_that_races_past_the_check_gets_the_original(): void
    {
        [$user, , , $click] = $this->scene();
        $first = Conversion::factory()->create(['click_id' => $click->id, 'event' => 'purchase', 'external_id' => 'order-1']);

        // The unique index is the real guard; the pre-check is only a shortcut.
        $this->report($user, $click, ['external_id' => 'order-1'])->assertOk()->assertJsonPath('id', $first->id);
    }

    public function test_a_conversion_for_a_site_with_tracking_off_is_refused_with_the_reason(): void
    {
        [$user, , , $click] = $this->scene(tracking: false);

        $this->report($user, $click)->assertUnprocessable()->assertJsonValidationErrors('click_id');
        $this->assertSame(0, Conversion::count());
    }

    public function test_a_click_too_old_to_be_credited_is_refused(): void
    {
        [$user, , $link] = $this->scene();
        $old = Click::factory()->create(['link_id' => $link->id]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $fresh = Click::factory()->create(['link_id' => $link->id]);
        $fresh->forceFill(['created_at' => now()->subDays(89)])->save();

        $this->report($user, $old)->assertUnprocessable()->assertJsonValidationErrors('click_id');
        $this->report($user, $fresh)->assertCreated();
    }

    public function test_the_attribution_window_is_configurable(): void
    {
        config(['features.conversions.attribution_days' => 7]);
        [$user, , $link] = $this->scene();
        $click = Click::factory()->create(['link_id' => $link->id]);
        $click->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->report($user, $click)->assertUnprocessable();
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        [$user] = $this->scene();

        $this->actingAs($user, 'sanctum')->postJson('/api/conversions', ['click_id' => 'nope', 'event' => 'signup'])->assertNotFound();
    }

    public function test_a_token_from_a_workspace_you_are_not_in_is_indistinguishable_from_an_unknown_one(): void
    {
        [, , , $click] = $this->scene();
        $stranger = User::factory()->create();

        $this->report($stranger, $click)->assertNotFound();
        $this->assertSame(0, Conversion::count());
    }

    public function test_a_viewer_can_see_the_click_but_not_report_on_it(): void
    {
        [$viewer, , , $click] = $this->scene(role: WorkspaceRole::Viewer);

        $this->report($viewer, $click)->assertForbidden();
    }

    public function test_an_editor_can_report(): void
    {
        [$editor, , , $click] = $this->scene(role: WorkspaceRole::Editor);

        $this->report($editor, $click)->assertCreated();
    }

    public function test_the_demo_account_cannot_report(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();
        $site = $demo->defaultWorkspace()->sites()->first();
        $site->update(['conversion_tracking' => true]);
        $click = Click::factory()->create(['link_id' => $site->links()->first()->id]);

        $this->report($demo, $click)->assertForbidden();
    }

    public function test_an_api_key_needs_write_access_and_the_right_workspace(): void
    {
        [$user, , , $click] = $this->scene();
        $read = $user->createToken('r', ['read'])->plainTextToken;
        $write = $user->createToken('w', ['read', 'write'])->plainTextToken;
        $elsewhere = $user->createToken('x', ['read', 'write', 'workspace:99999'])->plainTextToken;
        $body = ['click_id' => $click->token, 'event' => 'purchase'];

        $this->app['auth']->forgetGuards();
        $this->withToken($read)->postJson('/api/conversions', $body)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($elsewhere)->postJson('/api/conversions', $body)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($write)->postJson('/api/conversions', $body)->assertCreated();
    }

    public static function badInput(): array
    {
        return [
            'no click' => [['click_id' => null], 'click_id'],
            'no event' => [['event' => null], 'event'],
            'event with a space' => [['event' => 'big sale'], 'event'],
            'event too long' => [['event' => str_repeat('a', 65)], 'event'],
            'event starting with a symbol' => [['event' => '-x'], 'event'],
            'value that is not a number' => [['value' => 'lots', 'currency' => 'USD'], 'value'],
            'negative value' => [['value' => '-5', 'currency' => 'USD'], 'value'],
            'sub-cent precision' => [['value' => '1.999', 'currency' => 'USD'], 'value'],
            'absurdly large value' => [['value' => '99999999999', 'currency' => 'USD'], 'value'],
            'value without a currency' => [['value' => '5'], 'currency'],
            'currency that is not a code' => [['value' => '5', 'currency' => 'dollars'], 'currency'],
            'currency of digits' => [['value' => '5', 'currency' => '840'], 'currency'],
            'external id too long' => [['external_id' => str_repeat('x', 129)], 'external_id'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_bad_input_is_refused_with_the_field_at_fault(array $overrides, string $field): void
    {
        [$user, , , $click] = $this->scene();

        $this->actingAs($user, 'sanctum')->postJson('/api/conversions', $overrides + ['click_id' => $click->token, 'event' => 'purchase'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, Conversion::count());
    }

    public function test_a_value_is_stored_exactly_as_money_not_as_a_float(): void
    {
        [$user, , , $click] = $this->scene();

        $this->report($user, $click, ['value' => '0.10', 'currency' => 'USD', 'external_id' => 'a'])->assertCreated();
        $this->report($user, $click, ['value' => '0.20', 'currency' => 'USD', 'external_id' => 'b'])->assertCreated();

        $this->assertSame('0.30', number_format((float) Conversion::sum('value'), 2, '.', ''));
        $this->assertSame('0.10', Conversion::where('external_id', 'a')->value('value'));
    }

    public function test_the_recent_list_shows_what_arrived_without_the_click_token(): void
    {
        [$user, $site, $link, $click] = $this->scene();
        Conversion::factory()->create(['click_id' => $click->id, 'event' => 'signup']);
        Conversion::factory()->create(['click_id' => $click->id, 'event' => 'purchase', 'external_id' => 'o1', 'value' => '9.99', 'currency' => 'USD', 'source' => 'pixel']);
        $foreign = Link::factory()->for(Site::factory()->create())->create();
        Conversion::factory()->create(['click_id' => Click::factory()->create(['link_id' => $foreign->id])->id, 'event' => 'elsewhere']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/conversions")->assertOk();

        $this->assertSame(['purchase', 'signup'], array_column($response->json(), 'event'), 'newest first, this site only');
        $this->assertSame($link->short_code, $response->json('0.link'));
        $this->assertSame('pixel', $response->json('0.source'));
        $this->assertStringNotContainsString($click->token, $response->getContent());
    }

    public function test_only_members_can_read_the_recent_list(): void
    {
        [, $site] = $this->scene();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/sites/{$site->id}/conversions")->assertForbidden();
    }
}
