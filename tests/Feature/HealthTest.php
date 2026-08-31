<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_config_flags_read_the_paths_the_app_actually_uses(): void
    {
        /*
         * `bol` pointed at `connectors.sources.bol.client_id`, and there is no
         * `sources` key — so it resolved to null and reported false on every
         * environment, including ones where bol demonstrably works.
         *
         * A config check that is always "missing" is worse than none: it gets
         * ignored, or it sends somebody chasing a credential that was never
         * absent. Asserting both directions is the only way this stays honest,
         * since a wrong path passes any test that only checks the false case.
         */
        config([
            'giftcoves.connectors.bol.client_id' => null,
            // The suite runs on the `array` mailer, which counts as sendable —
            // so the transport has to be named for this to test anything.
            'mail.default' => 'resend',
            'services.resend.key' => null,
        ]);

        $off = $this->getJson('/health')->json('config');

        $this->assertFalse($off['bol']);
        $this->assertFalse($off['mail']);

        config([
            'giftcoves.connectors.bol.client_id' => 'a-client-id',
            'giftcoves.connectors.bol.client_secret' => 'a-secret',
            'services.resend.key' => 'a-key',
        ]);

        $on = $this->getJson('/health')->json('config');

        $this->assertTrue($on['bol'], 'bol is configured and the flag still says it is not');
        $this->assertTrue($on['mail']);
    }

    #[Test]
    public function the_mail_flag_follows_the_transport_actually_in_use(): void
    {
        /*
         * It used to read `services.resend.key` whatever the mailer was.
         *
         * Harmless while Resend was hardcoded in compose, and a lie the moment
         * production moved to OVH's SMTP: mail would work and health would
         * report `mail: false` forever. The same class of error as the eBay
         * flag that checked one half of a credential pair — a flag describing
         * a state it cannot observe.
         */
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'ssl0.ovh.net',
            'mail.mailers.smtp.username' => 'hello@example.com',
            'mail.mailers.smtp.password' => null,
            // Set, and irrelevant now. If the flag still consults it, this is
            // what catches that.
            'services.resend.key' => 'a-resend-key',
        ]);

        $this->assertFalse(
            $this->getJson('/health')->json('config.mail'),
            'SMTP with no password cannot send, whatever Resend is holding',
        );

        config(['mail.mailers.smtp.password' => 'a-password']);

        $this->assertTrue($this->getJson('/health')->json('config.mail'));

        // A deliberately silent mailer is doing what it was asked to. Flagging
        // it red is noise, and noise is how a health check gets ignored.
        config(['mail.default' => 'array']);

        $this->assertTrue($this->getJson('/health')->json('config.mail'));
    }

    #[Test]
    public function an_oauth_source_with_only_half_its_pair_reads_false(): void
    {
        /*
         * The state this flag exists to make visible, and the one it used to
         * hide.
         *
         * `supports()` requires both halves, so a connector with an id and no
         * secret is never called at all — it is absent from every search while
         * health reports it as present. That happened on production with eBay:
         * the flag said true, eBay returned nothing, and the search code was
         * suspected for an afternoon before the missing secret was.
         */
        config([
            'giftcoves.connectors.ebay.client_id' => 'an-app-id',
            'giftcoves.connectors.ebay.client_secret' => null,
            'giftcoves.connectors.bol.client_id' => 'a-client-id',
            'giftcoves.connectors.bol.client_secret' => null,
        ]);

        $config = $this->getJson('/health')->json('config');

        $this->assertFalse($config['ebay'], 'eBay has no secret, so it cannot work, and the flag must not say it can');
        $this->assertFalse($config['bol']);
    }

    #[Test]
    public function ebay_tracking_is_reported_separately_from_ebay_working(): void
    {
        /*
         * The two fail independently and only one of them is visible.
         *
         * Without a campaign id eBay still returns results, the links still
         * work, the visitor still buys — and the commission goes to nobody.
         * Folding it into `ebay` would report true for a connector earning
         * zero, which is the failure that surfaces months later as an empty
         * statement rather than as anything on the site.
         */
        config([
            'giftcoves.connectors.ebay.client_id' => 'an-app-id',
            'giftcoves.connectors.ebay.client_secret' => 'a-cert-id',
            'giftcoves.connectors.ebay.campaign_id' => ['EBAY_NL' => null, 'EBAY_FR' => null],
        ]);

        $config = $this->getJson('/health')->json('config');

        $this->assertTrue($config['ebay'], 'eBay is fully credentialed');
        $this->assertFalse($config['ebayTracking'], 'no campaign id anywhere, so every click earns nothing');

        config(['giftcoves.connectors.ebay.campaign_id' => ['EBAY_NL' => '5338111111', 'EBAY_FR' => null]]);

        // One configured marketplace is enough to say tracking exists at all.
        $this->assertTrue($this->getJson('/health')->json('config.ebayTracking'));
    }

    #[Test]
    public function it_reports_what_is_actually_running(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            /*
             * These are what turn "it looks fine after deploy" into a fact, and
             * there are three of them because the question has three parts.
             *
             * `commit` is which code — Coolify does expose SOURCE_COMMIT, and
             * the Dockerfile now takes it as a build arg. `built` is when the
             * image was made, and is cacheable: an unchanged commit reports the
             * previous build's stamp, so it must not be read as proof a deploy
             * landed. `started` is when this container came up, read from
             * /proc/1 per request and therefore never stale.
             *
             * Asserted as structure rather than value: on a laptop `commit` is
             * null and `started` is null off Linux, and pinning either would
             * make this test pass only inside a container.
             */
            ->assertJsonStructure([
                'status', 'commit', 'built', 'started', 'branch',
                'migration', 'environment', 'config', 'checks',
            ]);
    }

    #[Test]
    public function it_reports_whether_the_config_arrived(): void
    {
        // "Did the config carry over?" has to be answerable by curl after a
        // deploy, alongside built and migration. The count rather than a flag on
        // awinAccounts is deliberate: the failure this caught was two accounts
        // locally and one in production, which no boolean would have shown.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('config.appKey', true)
            ->assertJsonStructure(['config' => ['appKey', 'credentialsKey', 'claimHashSecret', 'awinAccounts', 'robotsAllow']]);
    }

    #[Test]
    public function the_config_section_reports_presence_and_never_content(): void
    {
        /*
         * This endpoint is unauthenticated, so the config block may say a key is
         * present and nothing else. Anything richer — a length, a prefix, the
         * first four characters — narrows a brute force and belongs behind the
         * shell that `bc:check-config` already requires.
         */
        $config = $this->getJson('/health')->json('config');

        foreach ($config as $key => $value) {
            $this->assertTrue(
                is_bool($value) || is_int($value),
                "config.{$key} must be a boolean or a count, never anything derived from the value itself.",
            );
        }

        $this->assertStringNotContainsString(
            (string) config('app.key'),
            (string) $this->getJson('/health')->getContent(),
        );
    }

    #[Test]
    public function it_does_not_leak_connection_details(): void
    {
        // This endpoint is unauthenticated. An exception message can carry a
        // connection string with credentials, so failures are logged, not returned.
        $body = $this->getJson('/health')->getContent();

        $this->assertStringNotContainsString(config('database.connections.pgsql.password'), (string) $body);
    }
}
