<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CookieConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Google tag, and the two things about it that can go wrong quietly.
 *
 * One: staging reporting into the same property as production. Staging serves a
 * full duplicate of the site on its own hosts, so its traffic is
 * indistinguishable from real traffic once it is in GA — there is no hostname
 * dimension that survives the comparison a year later. The gate is
 * `robots_allow`, which this repo already uses to mean "the real public site".
 *
 * Two: the tag *storing* something before anybody agreed to it. `_ga` is not
 * strictly necessary for anything the visitor asked for, so ePrivacy Art. 5(3)
 * wants a yes first.
 *
 * Since 2026-09-17 that second guarantee is Consent Mode rather than a withheld
 * script: the tag is on every page and every storage type starts denied. So
 * these tests assert on the consent commands, and the one thing that must never
 * appear without a yes is `'granted'`. The order matters as much as the values
 * — a default that arrives after gtag.js has started is a default that arrived
 * too late — so one test pins that too.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function live(): void
    {
        config([
            'giftcoves.robots_allow' => true,
            'giftcoves.google_analytics_id' => 'G-TESTID0001',
        ]);
    }

    #[Test]
    public function the_google_tag_is_rendered_for_a_visitor_who_accepted(): void
    {
        $this->live();

        $html = (string) $this->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->get('/be-nl')->assertOk()->getContent();

        $this->assertStringContainsString(
            'https://www.googletagmanager.com/gtag/js?id=G-TESTID0001',
            $html,
        );
        // The lifetime is asserted, not just the id: thirteen months is what
        // the privacy page commits to, and GA4's own default is two years.
        $this->assertStringContainsString("gtag('config', 'G-TESTID0001', {", $html);
        $this->assertStringContainsString('cookie_expires: 33696000', $html);

        // Asserted because the privacy page promises both in writing, and a
        // GA4 property checkbox is not something this repo can keep a promise
        // with. See resources/legal/*/privacy.md.
        $this->assertStringContainsString('allow_google_signals: false', $html);
        $this->assertStringContainsString('allow_ad_personalization_signals: false', $html);

        // The stored yes is replayed as a consent update, so a returning
        // visitor is never measured as a cookieless stranger.
        $this->assertStringContainsString("gtag('consent', 'update', {", $html);
        $this->assertStringContainsString("analytics_storage: 'granted'", $html);
        $this->assertStringContainsString("ad_storage: 'granted'", $html);
        $this->assertStringContainsString("ad_user_data: 'granted'", $html);

        /*
         * Advertising personalisation stays denied even for somebody who said
         * yes: both privacy pages promise that nothing measured here becomes an
         * advertising audience, and conversion measurement does not need it.
         * This assertion is the thing that makes that promise keepable — change
         * it only alongside the two privacy pages.
         */
        $this->assertStringContainsString("ad_personalization: 'denied'", $html);
        $this->assertStringNotContainsString("ad_personalization: 'granted'", $html);
    }

    #[Test]
    public function nothing_is_stored_before_the_question_has_been_answered(): void
    {
        $this->live();

        // No cookie at all: the visitor has not been asked yet, which is not a
        // yes. This is the state most first-time visitors are in, and it is the
        // state Google's own tag checker arrives in.
        $html = (string) $this->get('/be-nl')->assertOk()->getContent();

        // The tag is there — that is the whole point of the 2026-09-17 change.
        $this->assertStringContainsString('googletagmanager.com/gtag/js', $html);

        $this->assertStringContainsString("gtag('consent', 'default', {", $html);
        $this->assertStringContainsString("analytics_storage: 'denied'", $html);
        $this->assertStringContainsString("ad_storage: 'denied'", $html);
        $this->assertStringContainsString("ad_user_data: 'denied'", $html);

        // And nothing anywhere on the page says granted.
        $this->assertStringNotContainsString("'granted'", $html);
    }

    #[Test]
    public function the_denied_defaults_are_queued_before_the_library_is_requested(): void
    {
        $this->live();

        // Order, not just presence: gtag.js reads whatever is in dataLayer when
        // it starts, so a default written after the script tag is a default the
        // library never sees.
        $html = (string) $this->get('/be-nl')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'googletagmanager.com/gtag/js'),
            strpos($html, "gtag('consent', 'default', {"),
            'The consent defaults must be queued before gtag.js is requested.',
        );
    }

    #[Test]
    public function a_refusal_is_honoured_on_every_later_page(): void
    {
        $this->live();

        $html = (string) $this->withCookie(CookieConsent::COOKIE, CookieConsent::DENIED)
            ->get('/be-nl')->assertOk()->getContent();

        $this->assertStringContainsString("gtag('consent', 'default', {", $html);
        $this->assertStringNotContainsString("'granted'", $html);
    }

    #[Test]
    public function staging_does_not_report_into_the_production_property(): void
    {
        config([
            'giftcoves.robots_allow' => false,
            'giftcoves.google_analytics_id' => 'G-TESTID0001',
        ]);

        // Even from a visitor who accepted on production and arrived here with
        // the cookie still in the jar.
        $this->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->get('/be-nl')
            ->assertOk()
            ->assertDontSee('googletagmanager.com', escape: false);
    }

    #[Test]
    public function an_environment_can_switch_the_tag_off(): void
    {
        /*
         * An empty GA_MEASUREMENT_ID is the opt-out from *analytics*, and since
         * 2026-09-18 that is no longer the whole tag: the Ads account loads
         * gtag.js on its own, because a conversion cannot be reported without
         * it. So switching Google off entirely means clearing both ids, and
         * this test says so in both directions rather than asserting the old
         * half-truth.
         */
        config([
            'giftcoves.robots_allow' => true,
            'giftcoves.google_analytics_id' => '',
            'giftcoves.google_ads_conversion' => 'AW-TEST123/LabelTest',
        ]);

        $html = (string) $this->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->get('/be-nl')->assertOk()->getContent();

        // The tag loads for Ads, and configures no analytics property.
        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=AW-TEST123', $html);
        $this->assertStringNotContainsString("gtag('config', 'G-", $html);

        // Both cleared: nothing from Google at all.
        config(['giftcoves.google_ads_conversion' => '']);

        $this->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->get('/be-nl')
            ->assertOk()
            ->assertDontSee('googletagmanager.com', escape: false);
    }

    #[Test]
    public function the_outbound_click_conversion_is_configured_and_handed_to_the_client(): void
    {
        config([
            'giftcoves.robots_allow' => true,
            'giftcoves.google_analytics_id' => 'G-TESTID0001',
            'giftcoves.google_ads_conversion' => 'AW-TEST123/LabelTest',
        ]);

        $response = $this->get('/be-nl')->assertOk();

        /*
         * The account has to be configured on the tag: an event sent to an
         * account gtag.js was never configured for reports nowhere, and does it
         * silently. That silence is the reason this is asserted rather than
         * assumed.
         */
        $this->assertStringContainsString("gtag('config', 'AW-TEST123')", (string) $response->getContent());

        // The whole send_to travels in the props, because the thing that fires
        // it is a click listener in the browser, not the shell.
        $response->assertInertia(fn ($page) => $page->where('analytics.adsConversion', 'AW-TEST123/LabelTest'));
    }

    #[Test]
    public function staging_never_reports_a_conversion(): void
    {
        config([
            'giftcoves.robots_allow' => false,
            'giftcoves.google_ads_conversion' => 'AW-TEST123/LabelTest',
        ]);

        // Staging is a full duplicate of the site. A click there counting as a
        // conversion would bid real money against our own smoke tests.
        $this->get('/be-nl')
            ->assertOk()
            ->assertDontSee('AW-TEST123', escape: false)
            ->assertInertia(fn ($page) => $page->where('analytics.adsConversion', null));
    }

    #[Test]
    public function the_banner_appears_only_where_there_is_a_tag_to_consent_to(): void
    {
        config(['giftcoves.robots_allow' => false]);

        // Null id is what the banner branches on. A cookie banner on a site
        // that sets no non-essential cookie is theatre.
        $this->get('/be-nl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.id', null)
                ->where('analytics.consent', null));

        $this->live();

        $this->get('/be-nl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analytics.id', 'G-TESTID0001')
                ->where('analytics.consent', null));
    }

    #[Test]
    public function answering_the_banner_records_the_answer_and_does_not_move_the_visitor(): void
    {
        $this->live();

        $this->from('/be-nl')
            ->post('/consent', ['choice' => CookieConsent::GRANTED])
            ->assertRedirect('/be-nl')
            ->assertCookie(CookieConsent::COOKIE, CookieConsent::GRANTED);

        $this->from('/be-nl')
            ->post('/consent', ['choice' => CookieConsent::DENIED])
            ->assertCookie(CookieConsent::COOKIE, CookieConsent::DENIED);
    }

    #[Test]
    public function consent_can_be_withdrawn(): void
    {
        $this->live();

        // Clearing the cookie puts the question back, which is the footer's
        // Cookies link. Withdrawing has to be as easy as accepting was.
        $this->from('/be-nl')
            ->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->post('/consent', ['choice' => 'reset'])
            ->assertCookieExpired(CookieConsent::COOKIE);
    }

    #[Test]
    public function the_consent_endpoint_refuses_a_value_it_did_not_offer(): void
    {
        $this->from('/be-nl')
            ->post('/consent', ['choice' => 'maybe'])
            ->assertSessionHasErrors('choice');
    }
}
