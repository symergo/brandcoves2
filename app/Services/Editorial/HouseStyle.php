<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Services\Guides\CoveMarkup;

/**
 * House style, applied to prose on the way in — not on the way out.
 *
 * ## Why this is enforced in code and not only in a prompt
 *
 * Every rule here is also stated in the shipped prompts, and that is not enough
 * for either of the two reasons a rule gets dropped. Prompts are editable from
 * the admin panel, so a rewritten voice takes the rule with it and nobody sees
 * the change until an article is live. And a model asked for eight rules keeps
 * seven — a punctuation habit is exactly the instruction that loses, because
 * nothing about the output looks wrong when it is ignored.
 *
 * Applied at the write, so a stored article is already correct and every reader
 * of the column — the page, the digest email, the JSON-LD, the meta
 * description, the admin table — gets the same text without each one
 * remembering to filter. The alternative, filtering at render, is the version
 * where one of the six callers forgets.
 *
 * ## The two rules
 *
 * **Em dashes become a spaced hyphen.** `—` is the punctuation mark a language
 * model reaches for two or three times a paragraph, and at the density it
 * produces them it is the single most legible tell that nobody wrote this. A
 * spaced hyphen carries the same pause and is what a person typing into a form
 * would have produced. It is a substitution rather than a deletion because the
 * clause on either side still needs separating: dropping the mark entirely runs
 * two independent thoughts together and changes what the sentence says.
 *
 * **`**bold**` is Markdown, and nothing on this site renders Markdown.**
 * {@see CoveMarkup} escapes prose and resolves only its own
 * link tokens, deliberately — handing model output to something that interprets
 * markup is how a feed's stray angle bracket becomes a tag. So asterisks reached
 * the page as asterisks. In prose they now become `<strong>` at render, which
 * is why {@see prose()} leaves them alone. In a title, a blurb or a verdict
 * there is nothing to render them: those fields are printed as React text
 * nodes, so {@see plain()} takes the asterisks off and keeps the words.
 */
final class HouseStyle
{
    /**
     * Prose that will be rendered by `CoveMarkup` — an editorial, a body, an
     * intro, an FAQ answer.
     *
     * Emphasis survives: the renderer turns it into `<strong>`.
     */
    public static function prose(?string $text): ?string
    {
        return self::deDash($text);
    }

    /**
     * A field that is printed as text and never passes a renderer — a title, a
     * blurb, a verdict, an FAQ question, a meta description.
     */
    public static function plain(?string $text): ?string
    {
        $text = self::deDash($text);

        if ($text === null) {
            return null;
        }

        // Paired asterisks only, and the pair must wrap something. A lone `*`
        // is a person writing "5*" or a footnote marker, and deleting it would
        // change what the line says.
        return (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '$1', $text);
    }

    /**
     * `word — word` → `word - word`, `word—word` → `word - word`.
     *
     * The spacing is rebuilt rather than preserved, because the model emits all
     * three forms — spaced, unspaced and one-sided — and a straight
     * `str_replace` on the character alone turns the unspaced form into
     * `word-word`, which reads as a compound noun rather than a break.
     *
     * A dash with nothing before it (the start of a line) gets no leading
     * space, so a paragraph never opens on one.
     */
    private static function deDash(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, "\u{2014}")) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/(\S?)\h*\x{2014}\h*(\S?)/u',
            static function (array $m): string {
                $before = $m[1];
                $after = $m[2];

                return $before
                    .($before === '' ? '' : ' ')
                    .'-'
                    .($after === '' ? '' : ' ')
                    .$after;
            },
            $text,
        );
    }
}
