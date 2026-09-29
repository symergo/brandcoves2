<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Services\Search\GiftIntentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a gift search as intent (roadmap step 4). The owner's own example is
 * the first case, in every language; the rest hold the conservative rules: a
 * product search stays a product search, and a number is not a budget unless
 * something says it is.
 */
class GiftIntentParserTest extends TestCase
{
    /**
     * @param  array{relationship?: string|null, interests?: list<string>, occasion?: string|null, min?: int|null, max?: int|null, rest?: string, gift?: bool}  $expected
     */
    #[Test]
    #[DataProvider('searches')]
    public function a_search_is_read_as_intent(Market $market, string $text, array $expected): void
    {
        $parsed = app(GiftIntentParser::class)->parse($text, $market);

        $this->assertSame($expected['gift'] ?? true, $parsed->isGift, 'isGift');

        foreach (['relationship', 'occasion'] as $key) {
            if (array_key_exists($key, $expected)) {
                $this->assertSame($expected[$key], $parsed->{$key}, $key);
            }
        }

        if (array_key_exists('interests', $expected)) {
            $this->assertSame($expected['interests'], $parsed->interests, 'interests');
        }

        if (array_key_exists('min', $expected)) {
            $this->assertSame($expected['min'], $parsed->budgetMin, 'budgetMin');
        }

        if (array_key_exists('max', $expected)) {
            $this->assertSame($expected['max'], $parsed->budgetMax, 'budgetMax');
        }

        if (array_key_exists('rest', $expected)) {
            $this->assertSame($expected['rest'], $parsed->rest, 'rest');
        }
    }

    /** @return array<string, array{0: Market, 1: string, 2: array<string, mixed>}> */
    public static function searches(): array
    {
        // "zus" is a sister since the gender split (2026-09-29), not "broer of zus".
        $sisterGardening = ['relationship' => 'sister', 'interests' => ['gardening'], 'min' => 3000, 'max' => 5000, 'rest' => ''];

        return [
            'the owner\'s example, nl' => [Market::BeNl, 'cadeau voor mijn zus die van tuinieren houdt, €30–€50', $sisterGardening],
            'the owner\'s example, en' => [Market::En, 'Gift for my sister who loves gardening, €30–€50', $sisterGardening],
            'the owner\'s example, fr' => [Market::BeFr, 'cadeau pour ma sœur qui aime le jardinage, 30 à 50 €', $sisterGardening],
            'the owner\'s example, es' => [Market::Es, 'regalo para mi hermana que le gusta la jardinería, entre 30 y 50 euros', $sisterGardening],

            'dad, sixty, cooking, a range in words' => [Market::NlNl, 'cadeau voor papa die graag kookt tussen 50 en 100 euro', [
                'relationship' => 'father', 'interests' => ['cooking'], 'min' => 5000, 'max' => 10000,
            ]],
            'an occasion without a recipient is still a gift' => [Market::BeNl, 'kerstcadeau onder de 25', [
                'relationship' => null, 'occasion' => 'christmas', 'max' => 2500,
            ]],
            // "a friend" names no relation in English since the gender split
            // (2026-09-29); "my sister" does.
            'the interest label itself is recognised' => [Market::En, 'present for my sister into board games', [
                'relationship' => 'sister', 'interests' => ['boardgames'],
            ]],
            'two interests' => [Market::BeNl, 'cadeau voor mijn broer, fietsen en koffie', [
                'relationship' => 'brother', 'interests' => ['cycling', 'coffee'],
            ]],
            'what is left stays as search words' => [Market::En, 'gift for my mum, a silk scarf', [
                'relationship' => 'mother', 'rest' => 'silk scarf',
            ]],

            'a product search stays a product search' => [Market::BeNl, 'tuinhandschoenen', [
                'gift' => false, 'rest' => 'tuinhandschoenen', 'max' => null,
            ]],
            'an interest word alone is not a gift search' => [Market::En, 'gardening gloves', [
                'gift' => false, 'interests' => [], 'rest' => 'gardening gloves',
            ]],
            'a budget on a product search still counts' => [Market::BeNl, 'koptelefoon onder 100', [
                'gift' => false, 'max' => 10000, 'rest' => 'koptelefoon',
            ]],
            'a model number is not a budget' => [Market::En, 'iphone 15-16 case', [
                'gift' => false, 'min' => null, 'max' => null, 'rest' => 'iphone 15-16 case',
            ]],
            'a product code is not a price' => [Market::BeNl, 'lego 42125', [
                'gift' => false, 'max' => null, 'rest' => 'lego 42125',
            ]],
            '"man" alone is not a partner' => [Market::BeNl, 'parfum man', [
                'gift' => false, 'relationship' => null,
            ]],
        ];
    }

    #[Test]
    public function dropping_one_piece_gives_the_search_without_it(): void
    {
        $parsed = app(GiftIntentParser::class)->parse('cadeau voor mijn zus die van tuinieren houdt, €30–€50', Market::BeNl);

        $this->assertStringNotContainsString('tuinieren', $parsed->without('interest:gardening'));
        $this->assertStringContainsString('zus', $parsed->without('interest:gardening'));
        $this->assertStringNotContainsString('30', $parsed->without('budget'));
    }
}
