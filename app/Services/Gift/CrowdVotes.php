<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * What everybody's thumbs say about one product, as a number from -1 to 1.
 *
 * Pure, so the rules are tested without a database
 * (tests/Unit/CrowdVotesTest.php). The counts come from
 * {@see GiftFeedback::crowd()}: one vote per voter per product, so the number
 * of votes is the number of different people.
 *
 * ## The rules, and why
 *
 * - **Nothing below `min_voters` different people** (config
 *   `giftcoves.gift.feedback.min_voters`, 5). Below that one person's taste,
 *   or one person's mum's, would show in somebody else's results, which is
 *   both a privacy leak and noise. Checked here as well as in the query, so a
 *   caller that forgets cannot lower it.
 * - **The same kind of person first.** Votes cast for a mother are the
 *   better evidence for a mother; when they are too few, all votes for the
 *   product count, since "people liked this as a present" still says
 *   something.
 * - **Approval is (up - down) / votes**, so four ups and one down is 0.6.
 *   A net "no" is **halved**: a thumb down is more often about one person's
 *   taste than about the product, and a product the crowd is lukewarm on
 *   should sink a little, not vanish.
 * - **Confidence 0.7 at the threshold, 1.0 at twenty people**, on a log
 *   scale: the same curve crowd picks use (crowd-picks.md), so the two crowd
 *   signals grow sure at the same pace.
 *
 * The engine multiplies this by the profile's `crowd_votes` weight: 6 for
 * somebody else, a little over half of `crowd` (10). A thumb costs a second;
 * keeping something on a list is a stronger act, so it counts for more.
 */
final class CrowdVotes
{
    /** Where confidence reaches 1.0; the crowd-picks curve. */
    private const SURE_AT = 20;

    public static function strength(
        int $contextVoters,
        int $contextUp,
        int $allVoters,
        int $allUp,
        int $minVoters,
    ): float {
        $min = max(1, $minVoters);

        if ($contextVoters >= $min) {
            return self::approval($contextVoters, $contextUp, $min);
        }

        if ($allVoters >= $min) {
            return self::approval($allVoters, $allUp, $min);
        }

        return 0.0;
    }

    private static function approval(int $voters, int $up, int $min): float
    {
        $up = max(0, min($voters, $up));
        $approval = (2 * $up - $voters) / $voters;

        if ($approval < 0) {
            $approval *= 0.5;
        }

        $confidence = $min >= self::SURE_AT
            ? 1.0
            : 0.7 + 0.3 * min(1.0, log($voters / $min) / log(self::SURE_AT / $min));

        return $approval * $confidence;
    }
}
