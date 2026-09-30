<?php

namespace Tests\Unit;

use App\Support\ConversionUrl;
use PHPUnit\Framework\TestCase;

class ConversionUrlTest extends TestCase
{
    public function test_it_adds_the_token_to_a_url_with_no_query(): void
    {
        $this->assertSame('https://shop.example/landing?lf_click=abc123', ConversionUrl::withClick('https://shop.example/landing', 'abc123'));
    }

    public function test_it_appends_to_an_existing_query_without_re_encoding_it(): void
    {
        $url = 'https://shop.example/?q=red%20shoes%2C%20size%209&tag=a+b&flag&utm_source=news';

        $this->assertSame($url.'&lf_click=abc123', ConversionUrl::withClick($url, 'abc123'));
    }

    public function test_the_fragment_stays_at_the_end(): void
    {
        $this->assertSame('https://shop.example/docs?lf_click=t#install', ConversionUrl::withClick('https://shop.example/docs#install', 't'));
        $this->assertSame('https://shop.example/docs?a=1&lf_click=t#install', ConversionUrl::withClick('https://shop.example/docs?a=1#install', 't'));
    }

    public function test_a_token_already_on_the_url_is_replaced_not_doubled(): void
    {
        $this->assertSame('https://shop.example/?a=1&lf_click=new', ConversionUrl::withClick('https://shop.example/?lf_click=old&a=1', 'new'));
        $this->assertSame('https://shop.example/?lf_click=new', ConversionUrl::withClick('https://shop.example/?lf_click', 'new'));
    }

    public function test_it_does_not_mistake_a_longer_parameter_name_for_its_own(): void
    {
        $this->assertSame('https://shop.example/?lf_click_source=x&lf_click=t', ConversionUrl::withClick('https://shop.example/?lf_click_source=x', 't'));
    }

    public function test_a_stray_ampersand_does_not_leave_an_empty_parameter(): void
    {
        $this->assertSame('https://shop.example/?a=1&lf_click=t', ConversionUrl::withClick('https://shop.example/?&a=1&', 't'));
    }

    public function test_a_token_is_encoded_so_it_cannot_add_parameters(): void
    {
        $this->assertSame('https://shop.example/?lf_click=a%26b%3Dc%23d', ConversionUrl::withClick('https://shop.example/', 'a&b=c#d'));
    }
}
