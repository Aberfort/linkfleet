<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionPixelTest extends TestCase
{
    use RefreshDatabase;

    private function click(bool $tracking = true): Click
    {
        $site = Site::factory()->create(['conversion_tracking' => $tracking]);

        return Click::factory()->create(['link_id' => Link::factory()->for($site)->create()->id]);
    }

    private function assertIsPixel($response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertStringStartsWith('GIF89a', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_pixel_records_a_conversion_and_says_it_came_from_a_browser(): void
    {
        $click = $this->click();

        $this->assertIsPixel($this->get('/lf.gif?click='.$click->token.'&event=purchase&value=25.50&currency=eur&id=order-7'));

        $conversion = Conversion::sole();
        $this->assertSame('purchase', $conversion->event);
        $this->assertSame('25.50', $conversion->value);
        $this->assertSame('EUR', $conversion->currency);
        $this->assertSame('order-7', $conversion->external_id);
        $this->assertSame('pixel', $conversion->source);
        $this->assertSame($click->link_id, $conversion->link_id);
    }

    public function test_firing_the_pixel_again_does_not_double_count(): void
    {
        $click = $this->click();

        foreach (range(1, 3) as $ignored) {
            $this->get('/lf.gif?click='.$click->token.'&event=signup');
        }

        $this->assertSame(1, Conversion::count());
    }

    public function test_it_needs_no_login(): void
    {
        $click = $this->click();

        $this->assertIsPixel($this->getJson('/lf.gif?click='.$click->token.'&event=signup'));
        $this->assertSame(1, Conversion::count());
    }

    public static function everythingThatGoesWrong(): array
    {
        return [
            'no parameters' => [''],
            'an unknown token' => ['click=doesnotexist&event=signup'],
            'no event' => ['click=TOKEN'],
            'a malformed event' => ['click=TOKEN&event=big%20sale'],
            'money without a currency' => ['click=TOKEN&event=purchase&value=5'],
            'a value that is not a number' => ['click=TOKEN&event=purchase&value=abc&currency=USD'],
            'arrays where strings belong' => ['click[]=TOKEN&event[]=signup'],
        ];
    }

    #[DataProvider('everythingThatGoesWrong')]
    public function test_whatever_is_wrong_the_answer_is_still_the_image_and_nothing_is_recorded(string $query): void
    {
        $click = $this->click();

        $this->assertIsPixel($this->get('/lf.gif?'.str_replace('TOKEN', $click->token, $query)));

        $this->assertSame(0, Conversion::count());
    }

    public function test_a_valid_token_looks_no_different_from_an_invalid_one(): void
    {
        $click = $this->click();

        $valid = $this->get('/lf.gif?click='.$click->token.'&event=signup');
        $invalid = $this->get('/lf.gif?click=doesnotexist&event=signup');

        // Same status, same bytes, same headers that matter: nothing to probe with.
        $this->assertSame($valid->getContent(), $invalid->getContent());
        $this->assertSame($valid->getStatusCode(), $invalid->getStatusCode());
        $this->assertSame($valid->headers->get('Content-Type'), $invalid->headers->get('Content-Type'));
    }

    public function test_a_site_that_switched_tracking_off_stops_accepting_them(): void
    {
        $click = $this->click(tracking: false);

        $this->assertIsPixel($this->get('/lf.gif?click='.$click->token.'&event=signup'));

        $this->assertSame(0, Conversion::count());
    }

    public function test_a_stale_click_is_ignored(): void
    {
        $click = $this->click();
        $click->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->assertIsPixel($this->get('/lf.gif?click='.$click->token.'&event=signup'));

        $this->assertSame(0, Conversion::count());
    }

    public function test_it_works_on_a_branded_domain_too(): void
    {
        $click = $this->click();

        $this->assertIsPixel($this->call('GET', 'http://go.example.com/lf.gif', ['click' => $click->token, 'event' => 'signup']));
        $this->assertSame(1, Conversion::count());
    }

    public function test_it_is_throttled_per_address(): void
    {
        $click = $this->click();

        foreach (range(1, 120) as $ignored) {
            $this->get('/lf.gif?click='.$click->token.'&event=signup')->assertOk();
        }

        $this->get('/lf.gif?click='.$click->token.'&event=signup')->assertStatus(429);
    }

    public function test_a_failure_inside_recording_never_reaches_the_page_it_sits_on(): void
    {
        $click = $this->click();
        Event::listen('eloquent.creating: '.Conversion::class, fn () => throw new \RuntimeException('database on fire'));

        $this->assertIsPixel($this->get('/lf.gif?click='.$click->token.'&event=signup'));
    }
}
