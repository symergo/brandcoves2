<?php

declare(strict_types=1);

namespace App\Services\Community;

/**
 * The checks that do not need a model, run before the one that does.
 *
 * Three reasons this exists rather than handing everything to the AI triage:
 *
 * 1. **It works with `AI_ENABLED=false`.** The site has to function with the
 *    model switched off, and "every post waits for a human" is a working
 *    fallback only if the obvious spam has already been separated out.
 * 2. **It is not guessable.** A model's verdict shifts between versions; a
 *    regex for "contains a URL" holds still, and the rules that matter most
 *    here are exactly the flat ones.
 * 3. **It costs nothing.** A link-stuffed post is the common abuse and the
 *    cheapest to catch, and paying for a model call to notice it is silly.
 *
 * Everything here **holds** rather than rejects. A held post waits for a human
 * and its author is told it is being looked at; a rejection is a judgement, and
 * a regex has no business making one. False positives are therefore cheap —
 * somebody's honest post is read by a person a few hours later — which is what
 * lets the patterns be blunt.
 */
class PostScreen
{
    /**
     * Anything that looks like a way to send a reader somewhere else.
     *
     * Deliberately wider than a strict URL match: `example . com` and
     * `example(dot)com` are what somebody writes precisely *because* they know
     * a link would be caught. Answers carry products as rows rather than links
     * (see `CommunityAnswerPick`), so a URL in the prose has no legitimate use
     * on this surface at all.
     */
    private const LINKISH = [
        '~https?://~i',
        '~www\.~i',
        '~\b[a-z0-9-]+\s*(?:\.|\(dot\)|\[dot\])\s*(?:com|net|org|shop|store|be|nl|fr|es|io|co)\b~i',
    ];

    /** A way to be contacted off-site, which is how a marketplace scam starts. */
    private const CONTACT = [
        '~[a-z0-9._%+-]+\s*(?:@|\(at\))\s*[a-z0-9.-]+~i',
        // Eight or more digits with optional separators: a phone number in any
        // of the formats our four markets write them in.
        '~(?:\+?\d[\s./-]?){8,}~',
    ];

    /**
     * A reason to hold this text, or null if nothing here objects to it.
     *
     * The reason is a **key**, not a sentence: it is stored on the row and read
     * by an admin, and the copy that renders it lives in the language files
     * like everything else.
     */
    public function hold(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        /*
         * Contact details first, because an email address contains a domain
         * and would otherwise be reported as a link. Both hold the post either
         * way, so this only decides what the admin is told — and "somebody is
         * trying to take this off-site" is a different judgement from "somebody
         * pasted a shop link".
         */
        foreach (self::CONTACT as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return 'contact';
            }
        }

        foreach (self::LINKISH as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return 'link';
            }
        }

        /*
         * Shouting, measured on letters only.
         *
         * Counting every character would let punctuation and digits decide, and
         * a short post is all-caps often enough by accident that a floor of
         * twenty letters is worth having. "OK" and "ASAP" are not shouting.
         */
        $letters = preg_replace('~[^\p{L}]~u', '', $text) ?? '';

        if (mb_strlen($letters) >= 20 && mb_strtoupper($letters) === $letters) {
            return 'shouting';
        }

        return null;
    }
}
