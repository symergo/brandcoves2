<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\GiftProfile;
use App\Services\Gift\TasteProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a gift profile card stores, how it becomes Find a gift's answers,
 * and the line of words it shows. See docs/features/gift-profile-card.md.
 *
 * Laravel's TestCase only for the translator the summary uses; nothing here
 * touches the database.
 */
class GiftProfileTest extends TestCase
{
    #[Test]
    public function a_profile_is_stored_without_its_scores_or_rounds(): void
    {
        $stored = GiftProfile::fromProfile(new TasteProfile(
            interests: ['coffee', 'cooking'],
            scores: ['coffee' => 2.0, 'cooking' => 1.5],
            avoid: ['gaming'],
            budgetMin: 3000,
            budgetMax: 6000,
            answered: 12,
        ));

        $this->assertSame([
            'interests' => ['coffee', 'cooking'],
            'avoid' => ['gaming'],
            'budgetMin' => 3000,
            'budgetMax' => 6000,
            'preferences' => [],
        ], $stored);
    }

    #[Test]
    public function only_what_the_gift_finder_accepts_survives(): void
    {
        $clean = GiftProfile::clean([
            'interests' => ['coffee', 'not-an-interest', 'coffee'],
            'avoid' => ['coffee', 'gaming', 'nonsense'],
            'budgetMin' => 6000,
            'budgetMax' => 3000,
            // Vibe and values were removed site-wide (2026-09-29): an old card
            // that still carries them loses them on the way out.
            'vibe' => 'playful',
            'preferences' => ['vintage', 'modern', 'bogus'],
            'values' => ['handmade'],
        ]);

        $this->assertSame(['coffee'], $clean['interests']);
        // An interest cannot be both loved and avoided.
        $this->assertSame(['gaming'], $clean['avoid']);
        // A band upside down is no band.
        $this->assertNull($clean['budgetMin']);
        $this->assertNull($clean['budgetMax']);
        // One pole per axis: vintage and modern are the two ends of one.
        $this->assertSame(['vintage'], $clean['preferences']);
        $this->assertSame(['interests', 'avoid', 'budgetMin', 'budgetMax', 'preferences'], array_keys($clean));
    }

    #[Test]
    public function the_brief_is_in_the_wizards_units_and_spelling(): void
    {
        $brief = GiftProfile::brief([
            'interests' => ['coffee'],
            'avoid' => ['gaming'],
            'budgetMin' => 3000,
            'budgetMax' => 6000,
        ]);

        $this->assertSame(['coffee'], $brief['interests']);
        // Euros, as the wizard's budget field is.
        $this->assertSame(30, $brief['budget_min']);
        $this->assertSame(60, $brief['budget_max']);
        // By tag, never a title word (TasteBrief::avoidedInterests).
        $this->assertSame(['interest:gaming'], $brief['avoid']);
        // A card is somebody else's: it never picks one of the visitor's people.
        $this->assertNull($brief['recipient_id']);
        $this->assertFalse($brief['remember']);
    }

    #[Test]
    public function the_summary_reads_like_a_sentence_in_the_readers_language(): void
    {
        app()->setLocale('nl');

        $this->assertSame(
            'koffie, koken, '.__('site.gift.taste.budget', ['min' => '€30', 'max' => '€60']),
            GiftProfile::summary(['interests' => ['coffee', 'cooking'], 'budgetMin' => 3000, 'budgetMax' => 6000]),
        );
    }

    #[Test]
    public function a_card_needs_an_interest_or_a_budget(): void
    {
        $this->assertTrue(GiftProfile::isEmpty(['avoid' => ['gaming']]));
        $this->assertFalse(GiftProfile::isEmpty(['budgetMin' => 1000, 'budgetMax' => 2000]));
        $this->assertFalse(GiftProfile::isEmpty(['interests' => ['coffee']]));
    }
}
