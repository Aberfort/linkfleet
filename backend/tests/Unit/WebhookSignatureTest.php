<?php

namespace Tests\Unit;

use App\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"type":"link.clicked"}';

    public function test_the_header_carries_the_timestamp_and_an_hmac_over_timestamp_and_body(): void
    {
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);

        $this->assertSame(
            't=1700000000,v1='.hash_hmac('sha256', '1700000000.'.self::BODY, self::SECRET),
            $header
        );
    }

    public function test_a_genuine_signature_verifies(): void
    {
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);

        $this->assertTrue(WebhookSignature::verify($header, self::SECRET, self::BODY, now: 1_700_000_010));
    }

    public function test_a_changed_body_fails(): void
    {
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);

        $this->assertFalse(WebhookSignature::verify($header, self::SECRET, self::BODY.' ', now: 1_700_000_010));
    }

    public function test_the_wrong_secret_fails(): void
    {
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);

        $this->assertFalse(WebhookSignature::verify($header, 'whsec_other', self::BODY, now: 1_700_000_010));
    }

    public function test_a_replay_outside_the_tolerance_fails(): void
    {
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);

        $this->assertTrue(WebhookSignature::verify($header, self::SECRET, self::BODY, 300, now: 1_700_000_299));
        $this->assertFalse(WebhookSignature::verify($header, self::SECRET, self::BODY, 300, now: 1_700_000_301));
        // A timestamp from the future is as suspect as one from the past.
        $this->assertFalse(WebhookSignature::verify($header, self::SECRET, self::BODY, 300, now: 1_699_999_600));
    }

    public function test_the_timestamp_is_covered_by_the_signature(): void
    {
        // Moving the timestamp forward to dodge the replay window must not work.
        $header = WebhookSignature::header(self::SECRET, 1_700_000_000, self::BODY);
        $forged = preg_replace('/^t=\d+/', 't=1700009999', $header);

        $this->assertFalse(WebhookSignature::verify($forged, self::SECRET, self::BODY, now: 1_700_009_999));
    }

    public function test_malformed_headers_fail_instead_of_erroring(): void
    {
        foreach (['', 'garbage', 't=abc,v1=xyz', 'v1=abc', 't=1700000000', 't=,v1='] as $header) {
            $this->assertFalse(WebhookSignature::verify($header, self::SECRET, self::BODY, now: 1_700_000_000), $header);
        }
    }
}
