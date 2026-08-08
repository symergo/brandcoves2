<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\SendCoveDigest;
use App\Mail\CoveConfirmationMail;
use App\Mail\CoveDigestMail;
use App\Models\CoveSubscriber;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Daily Cove email.
 *
 * Two properties matter more than the plumbing:
 *
 *  1. **Nothing is ever sent to an unconfirmed address.** A form anyone can type
 *     any address into is a way to mail people who never asked, and the first
 *     time that happens at volume the sending domain is finished for months.
 *  2. **No Amazon product data leaves the site in an email.** The PA-API licence
 *     restricts the content, not the destination, so a compliant link next to an
 *     Amazon title does not launder it. See docs/features/amazon-compliance.md.
 */
class CoveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function seedEdition(string $date = '2026-08-08', Source $source = Source::Awin): DailyPickSet
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => $source->value, 'external_id' => 'shop-'.$source->value],
            ['name' => 'Shop '.$source->value],
        );

        $edition = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => $date,
            'theme_title' => 'Rond de tafel',
            'theme_blurb' => 'Voor avonden zonder scherm.',
            'theme_slug' => 'theme-board-games',
            'theme_source' => 'theme',
            'editorial' => 'Vandaag kijken we naar spellen. [[product:1|Een spel]] hoort erbij.',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        foreach (range(0, 2) as $i) {
            $group = ProductGroup::create([
                'market' => Market::BeNl->value,
                // An EAN identity, so the email link is a barcode search.
                'identity_key' => '400638133393'.$i,
                'identity_kind' => 'ean',
                'title' => "Bordspel {$i}",
                'slug' => "bordspel-{$i}",
                'brand' => 'Speelgoed',
                'image_url' => "https://example.test/{$i}.jpg",
                'min_price' => 2900,
                'max_price' => 3900,
                'median_price' => 3900,
                'offer_count' => 1,
                'merchant_count' => 2,
                'in_stock' => true,
            ]);

            Product::create([
                'source' => $source->value,
                'external_id' => "offer-{$source->value}-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $group->title,
                'price' => 2900,
                'affiliate_url' => "https://example.test/go/{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);

            DailyPick::create([
                'set_id' => $edition->id,
                'group_id' => $group->id,
                'rank' => $i + 1,
                'slug' => "bordspel-{$i}",
            ]);
        }

        return $edition->refresh();
    }

    #[Test]
    public function signing_up_sends_one_confirmation_and_nothing_else(): void
    {
        Mail::fake();

        $this->post('/be-nl/coves/subscribe', ['email' => 'Sam@Example.com'])
            ->assertRedirect();

        Mail::assertSent(CoveConfirmationMail::class, 1);
        Mail::assertNotSent(CoveDigestMail::class);

        $subscriber = CoveSubscriber::query()->firstOrFail();

        // Lowercased, or "Sam@" and "sam@" become two subscriptions and
        // therefore two copies of every edition.
        $this->assertSame('sam@example.com', $subscriber->getAttribute('email'));
        $this->assertNull($subscriber->confirmed_at);
        $this->assertNotNull($subscriber->getAttribute('unsubscribe_token'));
    }

    #[Test]
    public function an_unconfirmed_address_never_receives_a_digest(): void
    {
        Mail::fake();
        $this->seedEdition();

        $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertNotSent(CoveDigestMail::class);
    }

    #[Test]
    public function confirming_puts_them_on_the_list_and_burns_the_token(): void
    {
        Mail::fake();
        $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);

        $token = CoveSubscriber::query()->value('confirm_token');

        $this->get("/be-nl/coves/confirm/{$token}")->assertRedirect('/be-nl/daily');

        $subscriber = CoveSubscriber::query()->firstOrFail();
        $this->assertNotNull($subscriber->confirmed_at);

        // Cleared, so a link in an abandoned mailbox cannot re-confirm an
        // address that has since left.
        $this->assertNull($subscriber->getAttribute('confirm_token'));
        $this->get("/be-nl/coves/confirm/{$token}")->assertRedirect('/be-nl/daily');
    }

    #[Test]
    public function an_expired_confirmation_link_does_not_work(): void
    {
        Mail::fake();
        $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);

        $token = CoveSubscriber::query()->value('confirm_token');

        CoveSubscriber::query()->update(['confirm_sent_at' => now()->subHours(49)]);

        $this->get("/be-nl/coves/confirm/{$token}")->assertRedirect();
        $this->assertNull(CoveSubscriber::query()->value('confirmed_at'));
    }

    #[Test]
    public function the_form_never_reveals_whether_an_address_is_subscribed(): void
    {
        Mail::fake();

        // Otherwise the form is an oracle: type an address, read the response,
        // learn whether that person reads this site.
        $first = $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);
        $token = CoveSubscriber::query()->value('confirm_token');
        $this->get("/be-nl/coves/confirm/{$token}");

        $second = $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);

        $this->assertSame(
            $first->getSession()->get('status'),
            $second->getSession()->get('status'),
        );

        // And no second confirmation mail to someone already on the list.
        Mail::assertSent(CoveConfirmationMail::class, 1);
    }

    #[Test]
    public function a_confirmed_subscriber_gets_the_digest_exactly_once(): void
    {
        Mail::fake();
        $this->seedEdition();
        $this->confirmedSubscriber();

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');
        // A retried job must not mail the list again.
        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertSent(CoveDigestMail::class, 1);
        $this->assertSame(1, CoveSubscriber::query()->value('sent_count'));
    }

    #[Test]
    public function the_digest_links_by_barcode_rather_than_to_a_product_page(): void
    {
        Mail::fake();
        $this->seedEdition();
        $this->confirmedSubscriber();

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertSent(CoveDigestMail::class, function (CoveDigestMail $mail) {
            /*
             * /search?q={ean}, so the reader lands on the live comparison —
             * Amazon included, fetched live, on our page where it is licensed to
             * appear. The email itself carries a number and our own words.
             */
            $urls = array_column($mail->digest['finds'], 'url');

            foreach ($urls as $url) {
                $this->assertStringContainsString('/search?q=4006381333', $url);
            }

            return true;
        });
    }

    #[Test]
    public function an_amazon_only_find_is_left_out_of_the_email(): void
    {
        Mail::fake();

        // The PA-API licence restricts the *content*, so an Amazon title in an
        // email is a breach even when every link points at brandcoves.com.
        $this->seedEdition(source: Source::Amazon);
        $this->confirmedSubscriber();

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        // Nothing sendable and no puzzle: the job declines to send rather than
        // mailing a notification that a page exists.
        Mail::assertNotSent(CoveDigestMail::class);
    }

    #[Test]
    public function a_mixed_edition_sends_only_the_permitted_finds(): void
    {
        Mail::fake();

        $edition = $this->seedEdition();

        // Add one Amazon-only pick alongside the three Awin ones.
        $amazonMerchant = Merchant::create(['source' => Source::Amazon->value, 'external_id' => 'amazon', 'name' => 'Amazon']);

        $group = ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => '4006381339999',
            'identity_kind' => 'ean',
            'title' => 'Amazon-only bordspel',
            'slug' => 'amazon-only',
            'image_url' => 'https://example.test/a.jpg',
            'min_price' => 1900,
            'median_price' => 1900,
            'offer_count' => 1,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);

        Product::create([
            'source' => Source::Amazon->value,
            'external_id' => 'B0TEST',
            'market' => Market::BeNl->value,
            'merchant_id' => $amazonMerchant->id,
            'group_id' => $group->id,
            'title' => $group->title,
            'price' => 1900,
            'affiliate_url' => 'https://amazon.com.be/dp/B0TEST',
            'availability' => Availability::InStock->value,
            'status' => ProductStatus::Active->value,
        ]);

        DailyPick::create(['set_id' => $edition->id, 'group_id' => $group->id, 'rank' => 4, 'slug' => 'amazon-only']);

        $this->confirmedSubscriber();
        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertSent(CoveDigestMail::class, function (CoveDigestMail $mail) {
            $titles = array_column($mail->digest['finds'], 'title');

            $this->assertNotContains('Amazon-only bordspel', $titles);
            // Counted rather than silently dropped: "and one more on the page"
            // is both true and a reason to click.
            $this->assertSame(1, $mail->digest['omitted']);

            return true;
        });
    }

    #[Test]
    public function unsubscribing_works_from_a_link_and_stops_the_mail(): void
    {
        Mail::fake();
        $this->seedEdition();
        $subscriber = $this->confirmedSubscriber();

        // GET, because an email client cannot POST from a footer link and a
        // reader who cannot leave marks the mail as spam instead.
        $this->get('/be-nl/coves/unsubscribe/'.$subscriber->getAttribute('unsubscribe_token'))
            ->assertRedirect('/be-nl/daily');

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertNotSent(CoveDigestMail::class);
        // The row survives as evidence that someone opted out.
        $this->assertNotNull(CoveSubscriber::query()->value('unsubscribed_at'));
    }

    #[Test]
    public function one_click_unsubscribe_headers_are_present(): void
    {
        Mail::fake();
        $this->seedEdition();
        $this->confirmedSubscriber();

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertSent(CoveDigestMail::class, function (CoveDigestMail $mail) {
            $headers = $mail->headers()->text;

            // RFC 8058. Gmail and Yahoo require it of bulk senders, and without
            // List-Unsubscribe-Post the header is decorative.
            $this->assertArrayHasKey('List-Unsubscribe', $headers);
            $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

            return true;
        });
    }

    #[Test]
    public function resubscribing_reuses_the_row(): void
    {
        Mail::fake();

        $subscriber = $this->confirmedSubscriber();
        $this->get('/be-nl/coves/unsubscribe/'.$subscriber->getAttribute('unsubscribe_token'));

        $this->post('/be-nl/coves/subscribe', ['email' => 'sam@example.com']);

        // One row, not two — the unique index is what guarantees one copy of
        // each edition per address.
        $this->assertSame(1, CoveSubscriber::query()->count());
        $this->assertNull(CoveSubscriber::query()->value('unsubscribed_at'));
    }

    #[Test]
    public function no_digest_is_sent_without_a_published_edition(): void
    {
        Mail::fake();
        $this->confirmedSubscriber();

        SendCoveDigest::dispatchSync(Market::BeNl, '2026-08-08');

        Mail::assertNotSent(CoveDigestMail::class);
    }

    private function confirmedSubscriber(string $email = 'sam@example.com'): CoveSubscriber
    {
        return CoveSubscriber::create([
            'market' => Market::BeNl->value,
            'email' => $email,
            'confirmed_at' => now(),
            'unsubscribe_token' => CoveSubscriber::newToken(),
        ]);
    }
}
