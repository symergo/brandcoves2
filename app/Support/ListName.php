<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ListKind;
use App\Models\Wishlist;
use Illuminate\Support\HtmlString;

/**
 * A list's name inside a sentence, said so that it reads as a list's name.
 *
 * The owner's rule (2026-09-26): when text names a list, style the name the
 * same way everywhere. Before this a list's name sat in a sentence as plain
 * text on one screen, in quotes on the next and in bold on a third — and
 * nothing constrains what somebody calls a list, so "Bewaard in voor mama"
 * could not be read at all: where does the name start?
 *
 * The site draws the name with `ListName.tsx` (the kind's icon, medium weight).
 * The server cannot send a React component, so for every message that names a
 * list it sends the **pieces** instead: the finished sentence (for anything
 * that only reads text — tests, screen readers, old clients), the same
 * sentence with `:list` left in it, and the name and kind to put there. The
 * page splits the sentence on `:list` and draws the name in its place.
 *
 * E-mail cannot draw an icon reliably, so there the name is bold, through
 * {@see self::inMail()} and {@see self::mailSentence()}.
 *
 * ## Quotes around the placeholder
 *
 * Some sentences put the name in quotes ("A new message on “:list”"), because
 * the quotes were the only way plain text could mark where a name begins. Where
 * the name is styled, the style does that job and the quotes would say it
 * twice, so the pair directly around the placeholder is dropped — the same
 * rule `rich()` in `useTranslations.ts` applies on the page. The plain sentence
 * keeps them, because there nothing else marks the name.
 */
final class ListName
{
    /** The placeholder the page splits on; the same token the translations use. */
    public const TOKEN = ':list';

    /**
     * Opening and closing quotes that may sit directly around the placeholder,
     * across the four languages: curly English/Dutch/Spanish quotes, the
     * French guillemets (with their spaces), and the straight quotes a few
     * older strings still use.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const QUOTES = [
        ['“', '”'],
        ['„', '”'],
        ['„', '“'],
        ['‘', '’'],
        ['«', '»'],
        ['‹', '›'],
        ['"', '"'],
        ["'", "'"],
    ];

    /**
     * The pieces a page needs to draw a sentence naming a list.
     *
     * @param  array<string, string|int>  $params  the sentence's other placeholders
     * @return array{message: string, template: string, name: string, kind: string|null}
     */
    public static function mention(string $key, string $name, ?ListKind $kind, array $params = [], ?string $locale = null): array
    {
        return [
            'message' => (string) __($key, ['list' => $name] + $params, $locale),
            'template' => self::template($key, $params, $locale),
            'name' => $name,
            'kind' => $kind?->value,
        ];
    }

    /**
     * The same, for a list in hand.
     *
     * @param  array<string, string|int>  $params
     * @return array{message: string, template: string, name: string, kind: string|null}
     */
    public static function mentionList(string $key, Wishlist $list, array $params = [], ?string $locale = null): array
    {
        return self::mention($key, $list->displayTitle($locale), $list->kind, $params, $locale);
    }

    /**
     * Flash a confirmation that names a list, in both shapes.
     *
     * `success` stays the finished sentence, so everything that already read
     * it — `FlashMessage` on an old bundle, every test asserting on it — still
     * does. `success_list` is what the page draws from; `HandleInertiaRequests`
     * shares it as `flash.list`.
     *
     * Returned as an array for `->with()`, so a redirect keeps its one
     * expression.
     *
     * @param  array{message: string, template: string, name: string, kind: string|null}  $mention
     * @return array<string, mixed>
     */
    public static function flash(array $mention): array
    {
        return ['success' => $mention['message'], 'success_list' => $mention];
    }

    /**
     * The sentence with `:list` left in, so a page can put the styled name there.
     *
     * @param  array<string, string|int>  $params
     */
    public static function template(string $key, array $params = [], ?string $locale = null): string
    {
        return (string) __($key, ['list' => self::TOKEN] + $params, $locale);
    }

    /**
     * A list's name for a Markdown e-mail: bold, and nothing else.
     *
     * HTML rather than `**…**`, because a name is free text and `**Mama's*
     * list**` would come out as broken emphasis. Characters Markdown would act
     * on are written as entities, so the name reads exactly as it was typed.
     */
    public static function inMail(string $name): HtmlString
    {
        return new HtmlString('<strong>'.self::escapeForMarkdown($name).'</strong>');
    }

    /**
     * A translated sentence for a Markdown e-mail, with the list's name bold
     * and its surrounding quotes dropped.
     *
     * Everything else in the sentence is escaped too: the result is printed
     * raw, so a sender's name reaches the mail as text, never as markup.
     *
     * @param  array<string, string|int>  $params
     */
    public static function mailSentence(string $key, string $name, array $params = [], ?string $locale = null): HtmlString
    {
        $template = self::template($key, $params, $locale);
        $at = mb_strpos($template, self::TOKEN);

        if ($at === false) {
            return new HtmlString(self::escapeForMarkdown($template));
        }

        [$before, $after] = self::withoutQuotes(
            mb_substr($template, 0, $at),
            mb_substr($template, $at + mb_strlen(self::TOKEN)),
        );

        return new HtmlString(
            self::escapeForMarkdown($before).self::inMail($name)->toHtml().self::escapeForMarkdown($after),
        );
    }

    /**
     * Drop a pair of quotes that wraps the placeholder directly.
     *
     * Only a *pair*, matched on both sides: a closing quote belonging to some
     * other phrase must not be eaten because an opening one happens to sit
     * before the name.
     *
     * @return array{0: string, 1: string}
     */
    public static function withoutQuotes(string $before, string $after): array
    {
        foreach (self::QUOTES as [$open, $close]) {
            $b = preg_replace('/'.preg_quote($open, '/').'[\x{00A0}\x{202F} ]?$/u', '', $before, 1, $opened);
            $a = preg_replace('/^[\x{00A0}\x{202F} ]?'.preg_quote($close, '/').'/u', '', $after, 1, $closed);

            if ($opened === 1 && $closed === 1) {
                return [(string) $b, (string) $a];
            }
        }

        return [$before, $after];
    }

    /**
     * HTML-escaped, then the characters inline Markdown acts on as entities.
     * Not `#`: it only means a heading at the start of a line, and `e()` has
     * already written entities (`&#039;`) that a replaced `#` would break.
     */
    private static function escapeForMarkdown(string $text): string
    {
        return strtr(e($text), [
            '\\' => '&#92;',
            '*' => '&#42;',
            '_' => '&#95;',
            '`' => '&#96;',
            '[' => '&#91;',
            ']' => '&#93;',
            '~' => '&#126;',
        ]);
    }
}
