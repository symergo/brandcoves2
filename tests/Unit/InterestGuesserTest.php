<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Interest;
use App\Services\Gift\GiftTags;
use App\Services\Gift\InterestGuesser;
use App\Services\Gift\TasteCard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** What a product's own words say it is for. See resources/content/interest-words.php. */
class InterestGuesserTest extends TestCase
{
    private function guesser(): InterestGuesser
    {
        return new InterestGuesser([
            'interests' => [
                'coffee' => ['koffie', 'moka'],
                'cooking' => ['pan', 'kookboek'],
                'music' => ['t-shirt band', 'koptelefoon'],
            ],
            'not_gifts' => ['=filter', 'kabel', 'hoes'],
        ]);
    }

    #[Test]
    public function a_long_word_matches_at_the_start_of_a_word_only(): void
    {
        $g = $this->guesser();

        $this->assertSame(['coffee'], $g->interests('Koffiemolen elektrisch'));
        $this->assertSame([], $g->interests('Espressokoffie bonen'), 'not in the middle of a compound');
    }

    #[Test]
    public function a_short_word_must_be_the_whole_word(): void
    {
        $g = $this->guesser();

        $this->assertSame(['cooking'], $g->interests('Tefal pan 28 cm'));
        $this->assertSame([], $g->interests('Pantalon chino'));
        $this->assertSame(['coffee'], $g->interests('Bialetti Moka Express'));
    }

    #[Test]
    public function accents_case_and_punctuation_do_not_matter_and_the_category_counts(): void
    {
        $g = $this->guesser();

        $this->assertSame(['music'], $g->interests('KOPTELEFOON, draadloos'));
        $this->assertSame(['music'], $g->interests('Zwart', 'T-Shirt Band merch'));
        $this->assertSame(['coffee', 'cooking'], $g->interests('Kookboek: Café & koffie'));
    }

    #[Test]
    public function an_equals_sign_makes_a_long_word_whole_only(): void
    {
        $g = $this->guesser();

        $this->assertTrue($g->isNotAGift('Brita filter 3 stuks'));
        $this->assertFalse($g->isNotAGift('Filterkoffie apparaat'), 'a filter coffee machine is a gift');
        $this->assertTrue($g->isNotAGift('USB-C kabel 1m'));
        $this->assertTrue($g->isNotAGift('Telefoon', 'Hoes'));
    }

    #[Test]
    public function the_real_word_lists_name_only_real_interests(): void
    {
        $words = require resource_path('content/interest-words.php');
        $known = array_map(fn ($i) => $i->value, Interest::cases());

        $this->assertSame([], array_values(array_diff(array_keys($words['interests']), $known)));
    }

    #[Test]
    public function a_guess_counts_like_a_crowd_tag_and_a_tag_on_the_same_interest_wins(): void
    {
        $card = new TasteCard(id: 1, tags: [GiftTags::interest('coffee')], guessed: ['coffee', 'music']);

        $this->assertSame(['coffee' => 1.0, 'music' => TasteCard::GUESS_WEIGHT], $card->values(GiftTags::INTEREST));
        $this->assertSame(0.75, TasteCard::GUESS_WEIGHT);
    }
}
