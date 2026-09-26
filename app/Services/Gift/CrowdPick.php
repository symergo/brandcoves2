<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * One product that people shopping for someone like this kept on their lists.
 *
 * Carries only what the counts say: how strongly it matches the brief, how
 * many different people agree (never fewer than the threshold), and which
 * facts of the brief they agree on. Nothing about any list or person.
 */
final readonly class CrowdPick
{
    /**
     * @param  float  $strength  0 to 1: how closely and how surely it matches
     * @param  int  $owners  different people behind the best match
     * @param  list<string>  $tags  the facts of the brief that match, as gift tags
     */
    public function __construct(
        public int $groupId,
        public float $strength,
        public int $owners,
        public array $tags,
    ) {}

    /**
     * Whether the match is about the person, so the card may say "chosen for
     * someone like them".
     *
     * An occasion alone is not a person: "people picked this for Christmas"
     * is true of half the shop, and a label promising a match with the person
     * on that ground would be a small lie. It still lifts the product.
     */
    public function isAboutThePerson(): bool
    {
        foreach ($this->tags as $tag) {
            if (! str_starts_with($tag, GiftTags::OCCASION.':')) {
                return true;
            }
        }

        return false;
    }
}
