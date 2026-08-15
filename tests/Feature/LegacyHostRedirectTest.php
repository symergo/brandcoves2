<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * brandcoves.com -> giftcoves.com, path intact.
 *
 * The rename moved the site to a new registrable domain. Both are attached to
 * the same instance, so the app is what answers the old one.
 */
class LegacyHostRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.canonical_host' => 'giftcoves.com',
            'giftcoves.legacy_hosts' => ['brandcoves.com', 'www.brandcoves.com'],
        ]);
    }

    #[Test]
    public function the_old_domain_redirects_permanently_to_the_new_one(): void
    {
        $this->get('http://brandcoves.com/be-nl')
            ->assertStatus(301)
            ->assertRedirect('http://giftcoves.com/be-nl');
    }

    #[Test]
    public function the_path_and_query_survive_the_move(): void
    {
        /*
         * The assertion the whole migration rests on. Redirecting every old URL
         * to the homepage instead is how a site discards its index: a mass
         * redirect to `/` is treated as a soft 404 and the ranking is dropped
         * rather than transferred.
         */
        $this->get('http://brandcoves.com/be-nl/search?q=koptelefoon&brand%5B%5D=Sony')
            ->assertStatus(301)
            ->assertRedirect('http://giftcoves.com/be-nl/search?q=koptelefoon&brand%5B%5D=Sony');
    }

    #[Test]
    public function a_www_variant_is_a_legacy_host_too(): void
    {
        $this->get('http://www.brandcoves.com/be-nl/guides')
            ->assertStatus(301)
            ->assertRedirect('http://giftcoves.com/be-nl/guides');
    }

    #[Test]
    public function the_canonical_host_is_served_rather_than_redirected(): void
    {
        // Guards against a rule that matches everything and loops: a redirect
        // whose destination also redirects is an infinite chain, and the browser
        // reports it as ERR_TOO_MANY_REDIRECTS with no clue where it started.
        $this->get('http://giftcoves.com/be-nl')->assertOk();
    }

    #[Test]
    public function the_healthcheck_answers_on_any_host(): void
    {
        // Coolify reaches the container directly rather than through the domain.
        // A 301 here reads as an unhealthy container and rolls the deploy back.
        $this->get('http://brandcoves.com/health')->assertOk();
    }

    #[Test]
    public function nothing_redirects_when_no_canonical_host_is_configured(): void
    {
        // Local development and every environment before its cutover. The
        // feature ships switched off, so a deploy cannot start redirecting
        // before the DNS is ready for it.
        config(['giftcoves.canonical_host' => '']);

        $this->get('http://brandcoves.com/be-nl')->assertOk();
    }
}
