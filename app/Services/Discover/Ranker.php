<?php

declare(strict_types=1);

namespace App\Services\Discover;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The serendipity objective, MMR, and exploration.
 *
 * ```
 * score = relevance^α · unexpectedness^β · novelty^γ · quality
 * ```
 *
 * **Multiplicative, not a weighted sum.** A candidate that fails on quality
 * must not be able to buy its way back with unexpectedness — that is the
 * distinction between a discovery engine and a junk feed, and it is the same
 * reasoning as the Serendipity Engine's rarity × worth-seeing, generalised to
 * four terms. A sum would let a beautiful-but-irrelevant product beat a
 * relevant one in Search mode simply by being unusual.
 *
 * An exponent of zero neutralises its term (x⁰ = 1), which is what makes the
 * mode dial work: Search sets β = γ = 0 and the objective collapses to
 * `relevance^0.9 · quality`, with no special-casing anywhere.
 */
class Ranker
{
    /**
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    public function rank(array $candidates, ModeProfile $profile, int $limit, ?int $seed = null): array
    {
        $scored = array_map(
            fn (Candidate $c) => $c->scored(...$this->score($c, $profile)),
            $candidates,
        );

        usort($scored, fn (Candidate $a, Candidate $b) => $b->score <=> $a->score);

        return $this->diversify($scored, $profile, $limit, $seed);
    }

    /** @return array{0: float, 1: string} score and the dominant factor */
    private function score(Candidate $candidate, ModeProfile $profile): array
    {
        /*
         * A floor of 0.01 rather than 0.
         *
         * A single zero term annihilates the whole product, so a candidate with
         * no novelty signal at all would score zero in any mode with γ > 0 —
         * including every candidate from a retriever that does not set novelty.
         * The floor keeps a missing signal cheap instead of fatal.
         */
        $relevance = max(0.01, $candidate->signal('relevance', 0.5));
        $unexpected = max(0.01, $candidate->signal('unexpectedness', 0.3));
        $novelty = max(0.01, $candidate->signal('novelty', 0.3));
        $quality = max(0.01, $candidate->signal('quality', 0.8));

        $score = ($relevance ** $profile->alpha)
            * ($unexpected ** $profile->beta)
            * ($novelty ** $profile->gamma)
            * $quality;

        return [$score, $this->dominant($relevance, $unexpected, $novelty, $quality, $profile)];
    }

    /**
     * Which factor did the most work — the "why you're seeing this" line.
     *
     * Contribution, not raw signal: in Search mode β is zero, so a high
     * unexpectedness contributes exactly nothing and must not be reported as
     * the reason. Measuring the *exponentiated* term is what makes the
     * explanation honest about the mode the user is actually in.
     */
    private function dominant(
        float $relevance,
        float $unexpected,
        float $novelty,
        float $quality,
        ModeProfile $profile,
    ): string {
        /*
         * Two exclusions, both learned the hard way.
         *
         * **Zero-weight terms.** A zero exponent contributes exactly 0.0, and
         * every other contribution is negative (all inputs are ≤ 1, so every
         * log is ≤ 0). Left in, a neutralised term wins *always* — Deals would
         * explain every result as "unexpectedness" precisely because β = 0 and
         * unexpectedness is doing nothing at all.
         *
         * **Quality.** It is a gate, not a distinguishing factor: nearly every
         * surviving candidate scores 1.0 on it, log(1.0) is 0, and it would
         * then beat every real reason for the same arithmetic reason. "Well
         * stocked and easy to compare" is also a useless thing to tell someone
         * who searched for a specific product — it is true of everything on the
         * page, which is what makes it not an explanation.
         */
        $weighted = [
            'relevance' => [$profile->alpha, $relevance],
            'unexpectedness' => [$profile->beta, $unexpected],
            'novelty' => [$profile->gamma, $novelty],
        ];

        $contributions = [];

        foreach ($weighted as $name => [$weight, $value]) {
            if ($weight > 0.0) {
                // log, because these are multiplied: the log of a product is
                // the sum of the logs, so this compares like with like.
                $contributions[$name] = $weight * log(max(1e-6, $value));
            }
        }

        if ($contributions === []) {
            // No weighted term at all. Not reachable from any declared profile,
            // but an override row could do it, and a result with no reason is
            // worse than a blunt one.
            return 'quality';
        }

        // Every remaining log is negative, so the *least* negative contribution
        // is the one holding the score up.
        arsort($contributions);

        return (string) array_key_first($contributions);
    }

    /**
     * MMR, plus ε-greedy exploration.
     *
     * λ is the diversity knob: Search sets it low (0.2 — near-duplicates are
     * fine when you asked for a specific thing) and Serendipity sets it high
     * (0.8 — four of the same thing is a failed surprise).
     *
     * ε is the exploration rate. A purely greedy ranker never learns, because
     * it never shows anything it is unsure about, so nothing outside the
     * current top slice ever collects a reaction. Occasionally taking a random
     * candidate is what generates the training data the weights would be tuned
     * from.
     *
     * @param  list<Candidate>  $scored
     * @return list<Candidate>
     */
    private function diversify(array $scored, ModeProfile $profile, int $limit, ?int $seed): array
    {
        // Seeded so a test can assert on exploration without chasing a coin
        // flip, and so a reload with the same seed reproduces a page.
        $random = $seed === null ? null : new Randomizer(new Mt19937($seed));
        $roll = fn (): float => $random === null
            ? mt_rand() / mt_getrandmax()
            : $random->getInt(0, 999) / 1000;

        $pool = $scored;
        $picked = [];

        while (count($picked) < $limit && $pool !== []) {
            if ($profile->epsilon > 0 && $roll() < $profile->epsilon) {
                $index = array_rand($pool);
                $picked[] = $pool[$index];
                unset($pool[$index]);

                continue;
            }

            $bestIndex = null;
            $bestValue = -INF;

            foreach ($pool as $index => $candidate) {
                $penalty = 0.0;

                foreach ($picked as $chosen) {
                    $penalty = max($penalty, $this->similarity($candidate, $chosen));
                }

                $value = ((1 - $profile->lambda) * $candidate->score) - ($profile->lambda * $penalty);

                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $picked[] = $pool[$bestIndex];
            unset($pool[$bestIndex]);
        }

        return array_values($picked);
    }

    /**
     * How alike two results look to a person, 0..1.
     *
     * Category and brand dominate because they are what gets noticed — two
     * headphones from different brands still read as "you showed me headphones
     * twice". Title overlap catches the rest, and carries the weight where the
     * feed's category field is empty, which is often.
     */
    private function similarity(Candidate $a, Candidate $b): float
    {
        $score = 0.0;

        if ($a->group->category !== null && $a->group->category === $b->group->category) {
            $score += 0.6;
        }

        if ($a->group->brand !== null && $a->group->brand === $b->group->brand) {
            $score += 0.2;
        }

        $score += 0.4 * $this->titleOverlap($a->group->title, $b->group->title);

        return min(1.0, $score);
    }

    private function titleOverlap(string $left, string $right): float
    {
        $tokenise = static function (string $text): array {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // Short tokens are model numbers and noise words; they make
            // unrelated products look alike.
            return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 2)));
        };

        $a = $tokenise($left);
        $b = $tokenise($right);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        // Jaccard, so a long title cannot resemble everything just by having
        // more words in it.
        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }
}
