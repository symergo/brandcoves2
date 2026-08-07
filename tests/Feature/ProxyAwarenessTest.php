<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behind Coolify, Traefik terminates TLS and forwards plain HTTP.
 *
 * Without trusted proxies Laravel believes every request is insecure and
 * generates http:// URLs for redirects, assets, canonical tags and the sitemap
 * — while the pages themselves still render fine, which is what makes it easy
 * to ship unnoticed.
 */
class ProxyAwarenessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function forwarded_proto_makes_generated_urls_secure(): void
    {
        $location = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'staging.brandcoves.com',
        ])->get('/')->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringStartsWith(
            'https://',
            $location,
            'Root redirect must stay https behind a TLS-terminating proxy.',
        );
    }

    #[Test]
    public function forwarded_proto_marks_the_request_secure(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/be-nl')->assertOk();

        // The Location assertion above only proves the URL string. This proves
        // the framework actually treats the request as secure, which is what
        // asset() and url() depend on.
        $this->assertTrue(
            $this->app['request']->isSecure(),
            'Laravel must treat a proxied https request as secure.',
        );
    }

    #[Test]
    public function without_the_header_urls_stay_plain(): void
    {
        // Guards against the test above passing for the wrong reason: with no
        // forwarded proto the redirect must be http, otherwise the assertion
        // is not discriminating between the fixed and broken states.
        $location = $this->get('/')->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringStartsWith('http://', $location);
    }
}
