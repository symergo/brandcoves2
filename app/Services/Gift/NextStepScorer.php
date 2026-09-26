<?php

declare(strict_types=1);

namespace App\Services\Gift;

use Illuminate\Support\Str;

/**
 * "Last year the moka pot, this year the grinder."
 *
 * Decides which products follow on from what somebody was already given, and
 * why. Pure arithmetic over values handed in: no database, no clock, no AI.
 * {@see NextSteps} does the fetching. See docs/features/gift-history.md.
 *
 * ## Three reasons a product follows on
 *
 * | reason | evidence | weight |
 * |---|---|---|
 * | often together | five or more people keep both on their lists (`product_links`) | 0.5 to 1.0, by how many people |
 * | goes with | it is used with, or used up by, the past gift (the complement word lists) | 0.8 |
 * | same brand | the same maker's range | 0.6 for another kind of thing, 0.4 for the same kind |
 *
 * People's lists are the strongest evidence and can pass the word lists, but
 * only when many people agree: at the five-person floor a link weighs 0.625,
 * under a complement's 0.8, and it takes thirteen people to pass it. The word lists are an editor's knowledge of what
 * goes with what, and a product people keep together only by chance is
 * weaker than that. The brand is the weakest reason on its own, because a
 * brand's range is wide: a Bialetti moka pot says little about a Bialetti
 * saucepan. It is added on top when it agrees with a stronger reason.
 *
 * ## Never the same thing again
 *
 * A candidate whose title shares most of its words with a past gift is
 * another of the same thing (the six-cup moka pot after the three-cup), and
 * is dropped whatever else speaks for it. The past gifts' own products are
 * excluded by id before any of this, including products merged with them.
 *
 * ## Recent gifts count more
 *
 * Last year's present is what the next one should follow; one from five years
 * ago has probably been replaced, worn out or given away. Each year back
 * multiplies by 0.8, down to a floor of 0.4 so an old gift can still suggest
 * something when it is all there is.
 */
final class NextStepScorer
{
    /** A link at the five-person floor. */
    private const LINK_BASE = 0.5;

    private const LINK_MAX = 1.0;

    /** Owners at which a link counts fully: past twenty people, more agreement adds nothing. */
    private const LINK_SATURATES = 20;

    private const GOES_WITH = 0.8;

    private const SAME_BRAND_OTHER_KIND = 0.6;

    private const SAME_BRAND_SAME_KIND = 0.4;

    /** How much a second and third reason add to the strongest one. */
    private const EXTRA_REASON = 0.25;

    /** Share of title words in common at which a candidate is the same thing again. */
    private const TOO_SIMILAR = 0.6;

    private const RECENCY_DECAY = 0.8;

    private const RECENCY_FLOOR = 0.4;

    /**
     * Places per past gift before every other past gift has had its turn, so
     * one well-linked present does not fill the whole row while another has
     * something to say. See the two passes in {@see rank()}.
     */
    private const PER_PAST_GIFT = 2;

    /**
     * @param  list<PastGift>  $past  newest first
     * @param  list<NextStepCandidate>  $candidates
     * @param  array<int, array<int, int>>  $links  candidate id => [past gift's product id => people]
     * @param  array<string, array{triggers: list<string>, goes_with: list<string>}>  $families  the complement word lists
     * @param  list<int>  $exclude  product ids never to suggest (past gifts, their merges, what is already on the list)
     * @param  int|null  $budgetMax  cents; null is no ceiling
     * @return list<NextStep>
     */
    public function rank(
        array $past,
        array $candidates,
        array $links,
        array $families,
        array $exclude,
        ?int $budgetMax,
        int $thisYear,
        int $limit = 4,
    ): array {
        if ($past === [] || $candidates === [] || $limit <= 0) {
            return [];
        }

        $excluded = array_flip($exclude);
        $scored = [];

        foreach ($candidates as $candidate) {
            if (isset($excluded[$candidate->groupId])) {
                continue;
            }

            if ($budgetMax !== null && $candidate->price !== null && $candidate->price > $budgetMax) {
                continue;
            }

            $best = $this->best($candidate, $past, $links[$candidate->groupId] ?? [], $families, $thisYear);

            if ($best !== null) {
                $scored[] = $best;
            }
        }

        // Highest first; the product id breaks ties, so the same inputs always
        // give the same row.
        usort($scored, fn (array $a, array $b) => [$b['step']->score, $a['step']->groupId] <=> [$a['step']->score, $b['step']->groupId]);

        /*
         * Two passes. The first gives each past gift at most two places, so
         * a second past gift gets a say before a third idea for the first.
         * The second fills what is left from the rest, so a person with one
         * past gift still gets a full row.
         */
        $picked = [];
        $titles = [];

        foreach ([self::PER_PAST_GIFT, PHP_INT_MAX] as $cap) {
            $perPast = [];

            foreach ($picked as $entry) {
                $perPast[$entry['past']] = ($perPast[$entry['past']] ?? 0) + 1;
            }

            foreach ($scored as $entry) {
                if (count($picked) >= $limit) {
                    break 2;
                }

                if (isset($picked[$entry['step']->groupId]) || ($perPast[$entry['past']] ?? 0) >= $cap) {
                    continue;
                }

                // Two near-identical follow-ons (the same beans in two sizes)
                // are one idea shown twice.
                foreach ($titles as $title) {
                    if ($this->overlap($title, $entry['title']) >= self::TOO_SIMILAR) {
                        continue 2;
                    }
                }

                $picked[$entry['step']->groupId] = $entry;
                $perPast[$entry['past']] = ($perPast[$entry['past']] ?? 0) + 1;
                $titles[] = $entry['title'];
            }
        }

        // Back in score order: the second pass may have added a higher score
        // than one the first pass let through.
        $picked = array_values($picked);
        usort($picked, fn (array $a, array $b) => [$b['step']->score, $a['step']->groupId] <=> [$a['step']->score, $b['step']->groupId]);

        return array_map(fn (array $entry) => $entry['step'], $picked);
    }

    /**
     * The strongest case for this candidate against any one past gift.
     *
     * @param  list<PastGift>  $past
     * @param  array<int, int>  $links
     * @param  array<string, array{triggers: list<string>, goes_with: list<string>}>  $families
     * @return array{step: NextStep, past: int, title: string}|null
     */
    private function best(NextStepCandidate $candidate, array $past, array $links, array $families, int $thisYear): ?array
    {
        $best = null;

        foreach ($past as $index => $gift) {
            // Another of the same thing is never a next step.
            if ($this->overlap($gift->title, $candidate->title) >= self::TOO_SIMILAR) {
                return null;
            }

            $signals = [];

            if ($gift->groupId !== null && isset($links[$gift->groupId])) {
                $people = min(max($links[$gift->groupId], 0), self::LINK_SATURATES);
                $signals[NextStep::OFTEN_TOGETHER] = self::LINK_BASE + (self::LINK_MAX - self::LINK_BASE) * $people / self::LINK_SATURATES;
            }

            if ($this->goesWith($gift->title, $candidate->title, $families)) {
                $signals[NextStep::GOES_WITH] = self::GOES_WITH;
            }

            if ($this->sameBrand($gift->brand, $candidate->brand)) {
                $signals[NextStep::SAME_BRAND] = $this->sameKind($gift->category, $candidate->category)
                    ? self::SAME_BRAND_SAME_KIND
                    : self::SAME_BRAND_OTHER_KIND;
            }

            if ($signals === []) {
                continue;
            }

            arsort($signals);
            $reason = (string) array_key_first($signals);
            $top = (float) array_shift($signals);
            $score = ($top + self::EXTRA_REASON * array_sum($signals)) * $this->recency($gift->year, $thisYear);

            if ($best === null || $score > $best['step']->score) {
                $best = [
                    'step' => new NextStep($candidate->groupId, round($score, 4), $reason, $gift->title),
                    'past' => $index,
                    'title' => $candidate->title,
                ];
            }
        }

        return $best;
    }

    /**
     * Does the candidate belong with the past gift, by the complement word lists?
     *
     * The past gift's title has to name a family's thing ("moka") and the
     * candidate's title something that goes with it ("coffee beans",
     * "grinder"). Words match at the start of a word, so "grinder" finds
     * "grinders" and "tea" does not find "steak".
     *
     * @param  array<string, array{triggers: list<string>, goes_with: list<string>}>  $families
     */
    public function goesWith(string $pastTitle, string $candidateTitle, array $families): bool
    {
        $past = $this->normalise($pastTitle);
        $candidate = $this->normalise($candidateTitle);

        foreach ($families as $family) {
            if ($this->mentionsAny($past, $family['triggers'] ?? [])
                && $this->mentionsAny($candidate, $family['goes_with'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The families a title triggers, for the fetcher to know what to look for.
     *
     * @param  array<string, array{triggers: list<string>, goes_with: list<string>}>  $families
     * @return list<string> family keys
     */
    public function familiesOf(string $title, array $families): array
    {
        $text = $this->normalise($title);

        return array_values(array_filter(
            array_keys($families),
            fn (string $key) => $this->mentionsAny($text, $families[$key]['triggers'] ?? []),
        ));
    }

    /** @param list<string> $words */
    private function mentionsAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            $word = $this->normalise($word);

            if ($word !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brands compare by their slug, the way `brand_stats` does: "Audio-Technica"
     * and "Audio Technica" are one maker.
     */
    private function sameBrand(?string $a, ?string $b): bool
    {
        $a = Str::slug((string) $a);

        return $a !== '' && $a === Str::slug((string) $b);
    }

    /** Unknown category on either side is not the same kind: there is no evidence it is. */
    private function sameKind(?string $a, ?string $b): bool
    {
        $a = $this->normalise((string) $a);

        return $a !== '' && $a === $this->normalise((string) $b);
    }

    private function recency(?int $year, int $thisYear): float
    {
        if ($year === null) {
            return self::RECENCY_FLOOR;
        }

        // This year and last year both count fully: "last birthday" is last year.
        $back = max(0, $thisYear - $year - 1);

        return max(self::RECENCY_FLOOR, self::RECENCY_DECAY ** $back);
    }

    /** Jaccard over words of three letters or more, as SuggestionEngine::titleOverlap(). */
    private function overlap(string $left, string $right): float
    {
        $tokenise = function (string $text): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', $this->normalise($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 2)));
        };

        $a = $tokenise($left);
        $b = $tokenise($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }

    /** Lower case without accents, so "cafetière" meets "cafetiere". */
    private function normalise(string $text): string
    {
        return mb_strtolower(Str::ascii(trim($text)));
    }
}
