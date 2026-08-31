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
 * Two: the tag loading before anybody agreed to it. `_ga` is not strictly
 * necessary for anything the visitor asked for, so ePrivacy Art. 5(3) wants a
 * yes first — and a tag that loads and *then* checks has already fetched a
 * script from Google. Hence a server-side gate, and hence these tests assert on
 * the absence of the script tag rather than on any client-side behaviour.
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
    }

    #[Test]
    public function nothing_loads_before_the_question_has_been_answered(): void
    {
        $this->live();

        // No cookie at all: the visitor has not been asked yet, which is not a
        // yes. This is the state most first-time visitors are in.
        $this->get('/be-nl')
            ->assertOk()
            ->assertDontSee('googletagmanager.com', escape: false);
    }

    #[Test]
    public function a_refusal_is_honoured_on_every_later_page(): void
    {
        $this->live();

        $this->withCookie(CookieConsent::COOKIE, CookieConsent::DENIED)
            ->get('/be-nl')
            ->assertOk()
            ->assertDontSee('googletagmanager.com', escape: false);
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
        // An empty GA_MEASUREMENT_ID is the opt-out, and it has to win even
        // where the site is otherwise the live one.
        config([
            'giftcoves.robots_allow' => true,
            'giftcoves.google_analytics_id' => '',
        ]);

        $this->withCookie(CookieConsent::COOKIE, CookieConsent::GRANTED)
            ->get('/be-nl')
            ->assertOk()
            ->assertDontSee('googletagmanager.com', escape: false);
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
