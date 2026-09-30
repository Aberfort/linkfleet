<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Following a short link must not leave anything on the visitor's browser.
 * Laravel's web group would give every one of them a session and an XSRF
 * cookie; these routes need neither.
 */
class VisitorCookiesTest extends TestCase
{
    use RefreshDatabase;

    private function assertNoCookies(TestResponse $response): void
    {
        $this->assertSame([], $response->headers->getCookies(), 'the response must not set any cookie');
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_a_plain_redirect_sets_no_cookie(): void
    {
        $link = Link::factory()->create(['target_url' => 'https://example.com/x']);

        $response = $this->get("/r/{$link->short_code}")->assertRedirect('https://example.com/x');

        $this->assertNoCookies($response);
    }

    public function test_an_unknown_code_sets_no_cookie(): void
    {
        $this->assertNoCookies($this->get('/r/nope')->assertNotFound());
    }

    public function test_an_expired_link_sets_no_cookie(): void
    {
        $link = Link::factory()->create(['expires_at' => now()->subDay()]);

        $this->assertNoCookies($this->get("/r/{$link->short_code}")->assertStatus(410));
    }

    public function test_the_password_gate_sets_no_cookie_asking_or_answering(): void
    {
        $link = Link::factory()->create(['target_url' => 'https://example.com/secret']);
        $link->forceFill(['password' => bcrypt('open-sesame')])->save();

        $this->assertNoCookies($this->get("/r/{$link->short_code}")->assertOk()->assertSee('name="password"', false));
        $this->assertNoCookies($this->post("/r/{$link->short_code}", ['password' => 'wrong'])->assertStatus(422));
        $this->assertNoCookies($this->post("/r/{$link->short_code}", ['password' => 'open-sesame'])->assertRedirect('https://example.com/secret'));
    }

    public function test_the_gate_form_carries_no_csrf_token_that_nothing_would_check(): void
    {
        $link = Link::factory()->create();
        $link->forceFill(['password' => bcrypt('open-sesame')])->save();

        $this->get("/r/{$link->short_code}")->assertOk()->assertDontSee('name="_token"', false);
    }

    public function test_the_gate_works_without_any_token_or_session(): void
    {
        // What a real browser does: no cookie to send back, no token in the form.
        $link = Link::factory()->create(['target_url' => 'https://example.com/secret']);
        $link->forceFill(['password' => bcrypt('open-sesame')])->save();

        $this->post("/r/{$link->short_code}", ['password' => 'open-sesame'])->assertRedirect('https://example.com/secret');
        $this->assertSame(1, $link->clicks()->count());
    }

    public function test_a_qr_code_sets_no_cookie(): void
    {
        $link = Link::factory()->create();

        $this->assertNoCookies($this->get("/qr/{$link->short_code}.svg")->assertOk());
    }

    public function test_the_conversion_pixel_sets_no_cookie(): void
    {
        $this->assertNoCookies($this->get('/lf.gif?lf_click=abc&event=signup')->assertOk());
    }

    public function test_a_branded_domain_sets_no_cookie_either(): void
    {
        $site = Site::factory()->create();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        $link = Link::factory()->for($site)->create(['target_url' => 'https://example.com/brand']);

        $response = $this->get("http://go.example.com/{$link->short_code}")->assertRedirect('https://example.com/brand');

        $this->assertNoCookies($response);
    }

    public function test_the_api_sets_none_either(): void
    {
        $this->assertNoCookies($this->getJson('/api/config')->assertOk());
    }
}
