<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * The URL a page advertises for its social card.
 *
 * ## Why the URL carries the commit
 *
 * The card endpoint keys its cache on the commit precisely so a bad card cannot
 * outlive a deploy. That protection stopped at our own front door: the *URL* was
 * permanent, and we serve it with a week of `max-age`, so every platform that
 * had fetched a card went on showing those bytes no matter what the endpoint now
 * returned. A card rendered during a bad deploy — the `SITE.OG.DAILY` incident —
 * was fixed on the server and still wrong in every chat it had been shared into.
 *
 * WhatsApp makes this sharp, because it builds previews on the sender's device
 * and caches them there. There is no debugger to purge and no request we can
 * make. Changing the URL is the only lever that exists.
 *
 * ## Why the commit and not the drawn text
 *
 * {@see OgImageController::fingerprint()} hashes the exact strings the card
 * draws, which is the right key for a *cache* — it moves the moment the picture
 * would differ, including on a price change.
 *
 * It is the wrong key for a *URL*. Prices move constantly, and a card URL that
 * moved with them would give every product a fresh URL several times a day:
 * platform caching would never hit, and each change would cost a fetch and a
 * render across every platform the link had reached. It would also mean the
 * preview in a chat from last week silently rewrote itself, which is not what a
 * shared message is.
 *
 * The commit is the honest granularity. A shared card is a point-in-time
 * snapshot and stays put; a deploy — the only thing that can change how a card
 * is *drawn* rather than what it says — flushes every platform at once.
 *
 * Deriving the drawn text here would also mean re-implementing `offerLine()` and
 * the kicker translations outside the controller that owns them, and that pair
 * would drift. The commit needs neither.
 */
final class SocialCard
{
    /**
     * @param  string  $url  the absolute card URL, already market-prefixed
     */
    public static function versioned(string $url): string
    {
        $commit = config('giftcoves.commit_sha');

        // Null off a deployment, and then the URL is left exactly as it was:
        // a laptop has no commit to name, and inventing one would put a token
        // in the markup that means nothing. Twelve characters to match what
        // /health reports, so the two can be eyeballed against each other.
        if (! is_string($commit) || $commit === '') {
            return $url;
        }

        return $url.'?v='.substr($commit, 0, 12);
    }
}
