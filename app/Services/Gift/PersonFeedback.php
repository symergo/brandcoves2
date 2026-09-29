<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Models\ProductGroup;
use Illuminate\Support\Str;

/**
 * What the owner's thumbs taught about ideas for one saved person, and how
 * much a candidate looks like what they liked or turned down.
 *
 * Pure: built from the products that got a thumb, then asked about one
 * candidate at a time. No query, so the arithmetic is unit-tested directly
 * (tests/Unit/PersonFeedbackTest.php). The thumbs are read by
 * {@see GiftFeedback::forRecipient()}.
 *
 * ## What a thumb says about the rest of the catalogue
 *
 * A product is described by three things the engine already scores and a
 * shopper already notices: its interests (an editor's `interest:` tags and
 * the crowd's), its category, and its brand. A liked product lends each of
 * them a point, a disliked one takes half a point away:
 *
 * - **Up is +1, down is -0.5.** A "no" is often about the product (the wrong
 *   colour, one already at home, a price that felt off), not about cooking or
 *   the brand; a "yes" is a clearer statement about what suits the person.
 *   The disliked product itself never returns, which is the strong half of a
 *   no; its neighbours only lose a little.
 * - **Interest 0.5, category 0.35, brand 0.15.** An interest is what the
 *   person is about; a category is what kind of present; a brand is the
 *   weakest hint, since a person who liked one Le Creuset pan does not want
 *   the whole catalogue of the maker.
 * - **Each part saturates at one thumb's worth (clamped to -1..1)**, so three
 *   likes of cookbooks do not bury every other interest the person has.
 *
 * The result, -1..1, is multiplied by the profile's `feedback` weight (12 for
 * somebody else: see config/giftcoves.php). A full match on a liked product's
 * interest, category and brand is therefore worth 12 points, somewhat above
 * the taste pairs and well under interest fit: it reorders good answers, it
 * does not replace the brief.
 */
final readonly class PersonFeedback
{
    public const UP = 1.0;

    public const DOWN = -0.5;

    /** How much each kind of likeness counts; they sum to one. */
    public const KINDS = [
        'interest' => 0.5,
        'category' => 0.35,
        'brand' => 0.15,
    ];

    /**
     * @param  list<int>  $liked  group ids with a thumb up
     * @param  list<int>  $disliked  group ids with a thumb down: never suggested again for this person
     * @param  array<string, float>  $features  "kind:value" => net weight
     */
    public function __construct(
        public array $liked = [],
        public array $disliked = [],
        public array $features = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  iterable<ProductGroup>  $liked
     * @param  iterable<ProductGroup>  $disliked
     */
    public static function fromGroups(iterable $liked, iterable $disliked): self
    {
        $features = [];
        $likedIds = [];
        $dislikedIds = [];

        foreach ([[$liked, self::UP], [$disliked, self::DOWN]] as [$groups, $weight]) {
            foreach ($groups as $group) {
                if ($weight > 0) {
                    $likedIds[] = (int) $group->id;
                } else {
                    $dislikedIds[] = (int) $group->id;
                }

                foreach (self::featuresOf($group) as $feature) {
                    $features[$feature] = ($features[$feature] ?? 0.0) + $weight;
                }
            }
        }

        return new self($likedIds, $dislikedIds, $features);
    }

    public function isEmpty(): bool
    {
        return $this->liked === [] && $this->disliked === [];
    }

    /**
     * How much this candidate looks like what the person's thumbs said,
     * from -1 (only like what was turned down) to 1 (interest, category and
     * brand of something liked).
     */
    public function affinity(ProductGroup $group): float
    {
        if ($this->features === []) {
            return 0.0;
        }

        $byKind = [];

        foreach (self::featuresOf($group) as $feature) {
            $kind = strstr($feature, ':', true);
            $byKind[$kind] = ($byKind[$kind] ?? 0.0) + ($this->features[$feature] ?? 0.0);
        }

        $score = 0.0;

        foreach (self::KINDS as $kind => $weight) {
            $score += $weight * max(-1.0, min(1.0, $byKind[$kind] ?? 0.0));
        }

        return max(-1.0, min(1.0, $score));
    }

    /**
     * The three likenesses of a product, as "kind:value" keys.
     *
     * Interests from the editors' tags and the crowd's, as the engine's
     * interest fit reads them. The brand folded with Str::slug, the way brand
     * identity is folded everywhere (CLAUDE.md, search notes), so "Le
     * Creuset" and "LE CREUSET" are one brand.
     *
     * @return list<string>
     */
    public static function featuresOf(ProductGroup $group): array
    {
        $features = [];
        $prefix = GiftTags::INTEREST.':';

        foreach ([...$group->giftTags(), ...$group->crowdTags()] as $tag) {
            if (str_starts_with($tag, $prefix)) {
                $features[] = 'interest:'.substr($tag, strlen($prefix));
            }
        }

        $category = mb_strtolower(trim((string) $group->category));

        if ($category !== '') {
            $features[] = 'category:'.$category;
        }

        $brand = Str::slug((string) $group->brand);

        if ($brand !== '') {
            $features[] = 'brand:'.$brand;
        }

        return array_values(array_unique($features));
    }
}
