<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The visitor's address comes from Cloudflare's header since 2026-10-25, so
 * each visitor has their own throttle bucket. See UseCloudflareClientIp.
 */
class CloudflareClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', fn (Request $request) => $request->ip());
    }

    #[Test]
    public function the_address_is_cloudflares_connecting_ip_over_the_forwarded_for_header(): void
    {
        $this->get('/_test/ip', ['CF-Connecting-IP' => '203.0.113.7', 'X-Forwarded-For' => '198.51.100.1'])
            ->assertSee('203.0.113.7');
    }

    #[Test]
    public function without_the_header_or_with_a_bad_one_nothing_changes(): void
    {
        $this->get('/_test/ip')->assertSee('127.0.0.1');
        $this->get('/_test/ip', ['CF-Connecting-IP' => 'not an address'])->assertSee('127.0.0.1');
    }
}
