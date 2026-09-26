<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Services\Gift\BriefUrl;
use App\Services\Gift\GiftLandingCopy;
use App\Services\Gift\TasteBrief;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A brief as an address and back (roadmap step 4, part 1): every market's
 * words resolve to the value they stand for, nothing collides, and every
 * listing title fits.
 */
class BriefUrlTest extends TestCase
{
    #[Test]
    public function every_markets_words_come_back_as_what_they_stand_for(): void
    {
        foreach (Market::cases() as $market) {
            $seen = [];

            foreach (RecipientType::cases() as $recipient) {
                $slug = BriefUrl::recipientSlug($market, $recipient);

                $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug, "{$market->value}: {$recipient->value}");
                $this->assertNotContains($slug, $seen, "{$market->value}: '{$slug}' names two recipients");
                $this->assertSame($recipient, BriefUrl::recipient($market, $slug));
                $seen[] = $slug;
            }

            $seen = [];

            foreach (Interest::cases() as $interest) {
                $slug = BriefUrl::interestSlug($market, $interest);

                $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug, "{$market->value}: {$interest->value}");
                $this->assertNotContains($slug, $seen, "{$market->value}: '{$slug}' names two interests");
                $this->assertSame($interest, BriefUrl::interest($market, $slug), "{$market->value}: {$slug}");
                $seen[] = $slug;
            }
        }
    }

    #[Test]
    public function a_path_is_in_the_markets_own_words(): void
    {
        $this->assertSame('/be-nl/gift-ideas/for/papa/koken', BriefUrl::path(Market::BeNl, RecipientType::Father, Interest::Cooking));
        $this->assertSame('/nl-nl/gift-ideas/for/papa/koken', BriefUrl::path(Market::NlNl, RecipientType::Father, Interest::Cooking));
        $this->assertSame('/be-fr/gift-ideas/for/papa/cuisine', BriefUrl::path(Market::BeFr, RecipientType::Father, Interest::Cooking));
        $this->assertSame('/en/gift-ideas/for/dad/cooking', BriefUrl::path(Market::En, RecipientType::Father, Interest::Cooking));
        $this->assertSame('/es/gift-ideas/for/papa/cocina', BriefUrl::path(Market::Es, RecipientType::Father, Interest::Cooking));
        $this->assertSame('/en/gift-ideas/for/mum', BriefUrl::path(Market::En, RecipientType::Mother));
    }

    #[Test]
    public function another_languages_word_is_understood_and_nonsense_is_not(): void
    {
        // A hand-built French address with the Dutch word: understood, so
        // the page can redirect it rather than 404.
        $this->assertSame(Interest::Cooking, BriefUrl::interest(Market::BeFr, 'koken'));
        $this->assertNull(BriefUrl::interest(Market::BeFr, 'underwater-basket-weaving'));
        $this->assertNull(BriefUrl::recipient(Market::En, 'uncle'));
    }

    #[Test]
    public function a_brief_becomes_its_page_and_the_page_its_brief(): void
    {
        $brief = new TasteBrief(
            market: Market::BeNl,
            interests: ['not-a-thing', 'gardening', 'cooking'],
            budgetMin: 3000,
            budgetMax: 5000,
            relationship: 'sibling',
        );

        // The first interest the vocabulary knows goes in the path; the
        // budget is a parameter, in euros.
        $this->assertSame('/be-nl/gift-ideas/for/broer-of-zus/tuinieren?budget=30-50', BriefUrl::forBrief($brief));

        // Nobody to be for, no page.
        $this->assertNull(BriefUrl::forBrief(new TasteBrief(market: Market::BeNl, interests: ['gardening'])));

        $back = BriefUrl::toBrief(Market::BeNl, RecipientType::Sibling, Interest::Gardening, '30-50');
        $this->assertSame(['relationship' => 'sibling', 'interests' => ['gardening'], 'budgetMin' => 3000, 'budgetMax' => 5000], $back->toArray());
    }

    #[Test]
    public function a_budget_parameter_reads_three_ways_and_ignores_the_rest(): void
    {
        $this->assertSame([5000, 10000], BriefUrl::budget('50-100'));
        $this->assertSame([null, 2500], BriefUrl::budget('-25'));
        $this->assertSame([10000, null], BriefUrl::budget('100-'));
        $this->assertSame([2000, 5000], BriefUrl::budget('50-20'));
        $this->assertNull(BriefUrl::budget('-'));
        $this->assertNull(BriefUrl::budget('cheap'));
        $this->assertNull(BriefUrl::budget(null));

        $this->assertSame('50-100', BriefUrl::budgetParam(5000, 10000));
        $this->assertSame('-25', BriefUrl::budgetParam(null, 2500));
        $this->assertNull(BriefUrl::budgetParam(null, null));
    }

    #[Test]
    public function every_listing_title_fits_in_every_language(): void
    {
        // Measured after the words are filled in: "votre frère ou sœur qui
        // aime le nautisme" and "dad who loves music" are the same template.
        foreach ([Market::BeNl, Market::BeFr, Market::En, Market::Es] as $market) {
            foreach (RecipientType::cases() as $recipient) {
                $hub = (new GiftLandingCopy($market, $recipient))->title();
                $this->assertLessThanOrEqual(GiftLandingCopy::TITLE_BUDGET, mb_strlen($hub), $hub);

                foreach (Interest::cases() as $interest) {
                    $title = (new GiftLandingCopy($market, $recipient, $interest))->title();

                    $this->assertLessThanOrEqual(GiftLandingCopy::TITLE_BUDGET, mb_strlen($title), "{$market->value}: {$title}");
                    $this->assertStringNotContainsString(':', $title, "{$market->value}: an unfilled placeholder in {$title}");
                }
            }
        }

        $this->assertSame('Gift ideas for dad who loves cooking', (new GiftLandingCopy(Market::En, RecipientType::Father, Interest::Cooking))->title());
        $this->assertSame('Cadeau-ideeën voor je kind dat van gamen houdt', (new GiftLandingCopy(Market::BeNl, RecipientType::Child, Interest::Gaming))->heading());
    }
}
