<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One answer to "is the Google tag on here, and under which id".
 *
 * Three things have to agree — the blade shell that renders the tag, the
 * Inertia payload that decides whether to show the banner, and the client-side
 * loader that starts reporting the moment somebody accepts. When that rule
 * lived in the template, the banner and the tag could disagree about whether
 * analytics existed on this environment, and the visible symptom is a consent
 * banner asking permission for something that was never going to load.
 */
final class Analytics
{
    /**
     * The GA4 measurement id, or null where the tag must not render.
     *
     * Null on staging, and that is the whole point of the `robots_allow` gate:
     * staging serves a full duplicate of the site on its own hosts, so its
     * traffic is indistinguishable from real traffic once it is in the
     * property. There is no hostname dimension in GA that survives the
     * comparison a year later — the hits are simply mixed in. `robots_allow` is
     * the flag this repo already uses to mean "this is the real public site",
     * so it is the one this rides on rather than a second flag that could drift
     * out of step with it.
     *
     * An empty GA_MEASUREMENT_ID is an environment's way of opting out even
     * where the site is otherwise the live one.
     */
    public static function measurementId(): ?string
    {
        if (! config('giftcoves.robots_allow')) {
            return null;
        }

        $id = trim((string) config('giftcoves.google_analytics_id'));

        return $id === '' ? null : $id;
    }
}
