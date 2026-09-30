<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Link;
use App\Models\Site;
use App\Support\ClientIp;
use App\Support\GeoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MmdbFixture;
use Tests\TestCase;

class ClientAddressTest extends TestCase
{
    use RefreshDatabase;

    /** What Railway's edge looks like from inside: a private peer, and a chain ending in another proxy. */
    private const PRIVATE_PEER = 'fd12:462c:5ec1:1:b000:11f:7f94:69a7';

    private const VISITOR = '203.0.113.9';

    private const OTHER_PROXY = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'forwarded_for' => $request->headers->get('X-Forwarded-For'),
        ]));
    }

    /** A request as the edge would deliver it. */
    private function viaEdge(array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PRIVATE_PEER])->withHeaders($headers)->getJson('/_test/ip');
    }

    public function test_the_problem_without_the_setting_the_second_proxy_is_mistaken_for_the_visitor(): void
    {
        // Pins the behaviour that made this middleware necessary. With only the
        // immediate peer trusted, the rightmost untrusted hop wins.
        $this->viaEdge(['X-Forwarded-For' => self::VISITOR.', '.self::OTHER_PROXY, 'X-Real-IP' => self::VISITOR])
            ->assertJsonPath('ip', self::OTHER_PROXY);
    }

    public function test_with_the_setting_the_visitor_is_the_address_in_that_header(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Forwarded-For' => self::VISITOR.', '.self::OTHER_PROXY, 'X-Real-IP' => self::VISITOR])
            ->assertJsonPath('ip', self::VISITOR);
    }

    public function test_the_forwarded_chain_no_longer_disagrees(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Forwarded-For' => self::VISITOR.', '.self::OTHER_PROXY, 'X-Real-IP' => self::VISITOR])
            ->assertJsonPath('forwarded_for', self::VISITOR);
    }

    public function test_it_does_nothing_unless_asked(): void
    {
        $this->viaEdge(['X-Real-IP' => self::VISITOR])->assertJsonPath('ip', self::PRIVATE_PEER);
        // Directly, with no proxy in the picture at all.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['X-Real-IP' => self::VISITOR])->getJson('/_test/ip')->assertJsonPath('ip', '127.0.0.1');
    }

    public function test_a_different_header_can_be_named(): void
    {
        config(['features.client_ip_header' => 'CF-Connecting-IP']);

        $this->viaEdge(['CF-Connecting-IP' => self::VISITOR, 'X-Real-IP' => '192.0.2.99'])->assertJsonPath('ip', self::VISITOR);
    }

    public function test_ipv6_visitors_work(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Real-IP' => '2001:db8:1234::5'])->assertJsonPath('ip', '2001:db8:1234::5');
    }

    public function test_a_list_uses_its_first_entry(): void
    {
        config(['features.client_ip_header' => 'X-Forwarded-For']);

        $this->viaEdge(['X-Forwarded-For' => self::VISITOR.' , '.self::OTHER_PROXY])->assertJsonPath('ip', self::VISITOR);
    }

    public static function notAnAddress(): array
    {
        return [
            'garbage' => ['not-an-ip'],
            'out of range' => ['999.1.1.1'],
            'empty' => [''],
            'a hostname' => ['evil.example.com'],
            'with a port' => ['203.0.113.9:8080'],
            'a script' => ['<script>alert(1)</script>'],
            'a network' => ['203.0.113.0/24'],
            'only commas' => [' , ,'],
        ];
    }

    #[DataProvider('notAnAddress')]
    public function test_a_header_that_is_not_an_address_is_ignored_rather_than_believed(string $value): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Real-IP' => $value])->assertJsonPath('ip', self::PRIVATE_PEER);
    }

    public function test_without_the_header_the_request_is_treated_as_before(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Forwarded-For' => self::VISITOR])->assertJsonPath('ip', self::VISITOR);
    }

    public function test_the_protocol_the_proxy_reports_is_still_honoured(): void
    {
        // route() and short_url build https links from this; losing it would
        // hand out http:// short links.
        config(['features.client_ip_header' => 'X-Real-IP']);

        $this->viaEdge(['X-Real-IP' => self::VISITOR, 'X-Forwarded-Proto' => 'https'])->assertJsonPath('secure', true);
        $this->viaEdge(['X-Real-IP' => self::VISITOR, 'X-Forwarded-Proto' => 'http'])->assertJsonPath('secure', false);
    }

    public function test_every_reader_of_the_address_now_sees_the_visitor(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);
        $file = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($file, MmdbFixture::build([self::VISITOR.'/32' => 'UA', self::OTHER_PROXY.'/32' => 'PL']));
        $this->app->instance(GeoIp::class, new GeoIp($file));
        Link::factory()->for(Site::factory()->create())->create(['short_code' => 'promo']);

        $this->viaEdgeGet('/r/promo', ['X-Forwarded-For' => self::VISITOR.', '.self::OTHER_PROXY, 'X-Real-IP' => self::VISITOR]);

        $click = Click::sole();
        $this->assertSame('UA', $click->country, 'placed by the visitor, not by the proxy in front');
        $this->assertSame((new ClientIp)->truncateAndHash(self::VISITOR), $click->ip_hash);
        @unlink($file);
    }

    public function test_visitors_behind_the_same_proxy_are_told_apart(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);
        Link::factory()->for(Site::factory()->create())->create(['short_code' => 'promo']);

        foreach (['203.0.113.9', '198.51.100.7', '192.0.2.44'] as $visitor) {
            $this->viaEdgeGet('/r/promo', ['X-Real-IP' => $visitor, 'X-Forwarded-For' => $visitor.', '.self::OTHER_PROXY]);
        }

        $this->assertSame(3, Click::distinct()->count('ip_hash'), 'three people, not one shared proxy');
    }

    public function test_a_shared_proxy_no_longer_puts_everyone_in_one_rate_limit(): void
    {
        config(['features.client_ip_header' => 'X-Real-IP']);
        $click = Click::factory()->create(['link_id' => Link::factory()->for(Site::factory()->create(['conversion_tracking' => true]))->create()->id]);

        foreach (range(1, 120) as $ignored) {
            $this->viaEdgeGet('/lf.gif?click='.$click->token.'&event=signup', ['X-Real-IP' => '203.0.113.9'])->assertOk();
        }

        // That visitor is at the ceiling...
        $this->viaEdgeGet('/lf.gif?click='.$click->token.'&event=signup', ['X-Real-IP' => '203.0.113.9'])->assertStatus(429);
        // ...and a different visitor behind the very same proxy is unaffected.
        $this->viaEdgeGet('/lf.gif?click='.$click->token.'&event=signup', ['X-Real-IP' => '198.51.100.7'])->assertOk();
    }

    public function test_without_the_setting_the_shared_proxy_is_one_bucket_which_is_why_it_matters(): void
    {
        $click = Click::factory()->create(['link_id' => Link::factory()->for(Site::factory()->create(['conversion_tracking' => true]))->create()->id]);

        foreach (range(1, 120) as $i) {
            $this->viaEdgeGet('/lf.gif?click='.$click->token.'&event=signup', ['X-Real-IP' => '203.0.113.'.($i % 250), 'X-Forwarded-For' => "198.51.{$i}.1, ".self::OTHER_PROXY]);
        }

        $this->viaEdgeGet('/lf.gif?click='.$click->token.'&event=signup', ['X-Real-IP' => '192.0.2.1', 'X-Forwarded-For' => '192.0.2.1, '.self::OTHER_PROXY])
            ->assertStatus(429);
    }

    private function viaEdgeGet(string $uri, array $headers)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PRIVATE_PEER])->withHeaders($headers)->get($uri);
    }
}
