<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\ProductGroup;
use Carbon\CarbonInterface;

/**
 * Scores how likely a product is to make someone say "I didn't know that existed".
 *
 * This is the engine behind Daily Picks, the gift engine's `surprise` signal and
 * the /surprise surface. It ranks for the opposite of what a retailer ranks
 * for: a shop wants to show you the thing everyone buys, and by definition you
 * have already seen it.
 *
 * ## Serendipity is not obscurity
 *
 * The failure mode this class is built around: "surprising" and "nobody stocks
 * it because it is rubbish" are numerically identical if you only measure
 * rarity. A no-name phone case from a single merchant with no image scores
 * beautifully on every rarity signal and is a terrible thing to put in front of
 * anyone.
 *
 * So rarity is only half of it. {@see score()} multiplies a **rarity** score by
 * a **worth-seeing** score, and a product has to earn both. A thing nobody else
 * sells *and* that looks good *and* that a real shop stands behind is
 * serendipity; two out of three is noise.
 *
 * Pure and deterministic: catalogue statistics in, a number out. No AI, no
 * network, no clock beyond the one passed in.
 */
class SerendipityEngine
{
    /**
     * Signal weights, summing to 100 within each half.
     *
     * @var array<string, float>
     */
    private const RARITY_WEIGHTS = [
        // The strongest signal by a distance. An unusual noun in a title is
        // what "you didn't know this existed" actually looks like in data.
        'lexical' => 40.0,
        // A category with few products in it is a corner of the catalogue
        // nobody browses.
        'category' => 20.0,
        // A brand you have never heard of, measured honestly: share of the
        // catalogue, not a curated list of "cool" brands.
        'brand' => 15.0,
        // Sold by one shop rather than all of them.
        'exclusivity' => 15.0,
        // New to us. Weak — newness fades and rarity does not — but it is what
        // keeps the surface from showing the same twenty things forever.
        'novelty' => 10.0,
    ];

    public function __construct(
        private readonly CatalogueStats $stats,
        private readonly ?CarbonInterface $now = null,
    ) {}

    /**
     * 0-100, with the arithmetic attached.
     *
     * @return array{score: float, breakdown: array<string, float>}
     */
    public function score(ProductGroup $group): array
    {
        $gate = $this->worthSeeing($group);

        if ($gate === 0.0) {
            // Short-circuit rather than score-then-zero, so the breakdown says
            // plainly that it was gated and not that it scored badly.
            return ['score' => 0.0, 'breakdown' => ['gated' => 1.0]];
        }

        $signals = [
            'lexical' => $this->stats->lexicalRarity($group->title),
            'category' => $this->rarityOfShare($this->stats->categoryShare($group->category)),
            'brand' => $this->rarityOfShare($this->stats->brandShare($group->brand)),
            'exclusivity' => $this->exclusivity($group),
            'novelty' => $this->novelty($group),
        ];

        $breakdown = [];
        foreach ($signals as $name => $value) {
            $breakdown[$name] = round($value * self::RARITY_WEIGHTS[$name], 2);
        }

        $rarity = array_sum($breakdown);

        // Multiplied, not added. A product that fails the quality gate should
        // not be able to buy its way back with rarity — that is the whole
        // distinction between serendipity and junk.
        $breakdown['quality'] = round($gate, 2);

        return [
            'score' => round($rarity * $gate, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Is this worth putting in front of a person at all? 0-1.
     *
     * A gate rather than a signal. Everything here is a reason a rare product
     * is rare in a bad way.
     */
    private function worthSeeing(ProductGroup $group): float
    {
        // Hard requirements. A card with no image reads as broken, and an
        // unbuyable suggestion is a broken promise.
        if ($group->image_url === null || $group->min_price === null || ! $group->in_stock) {
            return 0.0;
        }

        // The classifier has already decided this is a consumable, a spare part
        // or a warranty. Those are extremely rare *and* extremely unwelcome.
        if ($group->giftable === false) {
            return 0.0;
        }

        $quality = 1.0;

        /*
         * Very cheap things are rare for the wrong reason: they are the tail of
         * accessories, cable ties and phone charms that no two shops bother to
         * list identically. Not excluded outright — a €6 curiosity is a fine
         * find — but they have to be unusually rare to compete.
         */
        if ($group->min_price < 1000) {
            $quality *= 0.5;
        }

        /*
         * A title with no meaningful words is a feed artefact: "ART.4471-B",
         * "Model 22 (zwart)". Rare by construction, worthless to show.
         */
        if ($this->meaningfulWords($group->title) < 2) {
            return 0.0;
        }

        // No brand at all is weak evidence of a white-label listing. Weak
        // enough to be a nudge rather than a gate — plenty of genuine finds are
        // sold unbranded.
        if ($group->brand === null) {
            $quality *= 0.8;
        }

        return $quality;
    }

    /**
     * Turn "share of catalogue" into "how rare", 0-1.
     *
     * Log-scaled for the same reason as lexical rarity: perceptually, 0.1% and
     * 2% are worlds apart and linearly they are both "small".
     */
    private function rarityOfShare(float $share): float
    {
        if ($share <= 0.0) {
            // Unknown category or brand. Genuinely uninformative — not rare,
            // just unmeasured — so it scores neutral rather than maximal.
            return 0.5;
        }

        return max(0.0, min(1.0, -log10(max($share, 1e-4)) / 4));
    }

    /**
     * Sold by few shops rather than all of them.
     *
     * Inverted deliberately against the search ranking, which prefers
     * comparable products. Both are right: when you know what you want, more
     * shops is better; when you are being shown something new, one shop having
     * it is the reason it is new to you.
     */
    private function exclusivity(ProductGroup $group): float
    {
        return match (true) {
            $group->merchant_count <= 1 => 1.0,
            $group->merchant_count === 2 => 0.6,
            $group->merchant_count === 3 => 0.3,
            default => 0.0,
        };
    }

    /** Decays over roughly a month; anything older contributes nothing. */
    private function novelty(ProductGroup $group): float
    {
        $firstSeen = $group->first_seen_at;

        if ($firstSeen === null) {
            return 0.0;
        }

        $days = $firstSeen->diffInDays($this->now ?? now());

        return max(0.0, min(1.0, 1.0 - ($days / 30)));
    }

    private function meaningfulWords(string $title): int
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_filter($words, fn (string $w) => mb_strlen($w) > 3));
    }
}
