<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\Market;
use App\Models\Wishlist;

/**
 * "My wishlist", in whichever language the reader is being served.
 *
 * The title of a list is a stored string, because most titles are typed by a
 * person and belong to them. The default list's title is the exception: nobody
 * chose it, we did — and storing it froze it in the language of whichever market
 * the owner happened to be on when the list was created. Someone who started on
 * `/en` and then switched to `/be-nl` had a list called "My wishlist" sitting
 * among Dutch pages, and `SharedListController` — which replaces our own name
 * with "Sanne's wishlist" for visitors — compared against the *current* locale's
 * spelling only, so it failed to recognise its own default and showed a link in
 * a group chat titled "My wishlist", belonging to nobody.
 *
 * So the stored value is a record of what we wrote, and the rendered value is
 * looked up fresh. {@see Wishlist::displayTitle()} is the only thing
 * that should read it.
 */
class DefaultTitle
{
    /**
     * Titles this application has given the default list, in every language it
     * speaks. Anything else was typed by a person.
     *
     * The names predating {@see DefaultList} are listed literally because they
     * are no longer in any language file — nothing renders "Saved items" any
     * more, but rows created before the rename still carry it.
     *
     * @var list<string>
     */
    private const RETIRED = [
        'Saved items',
        'Bewaard',
        'Enregistrés',
        'Guardados',
    ];

    /**
     * What to call the default list.
     *
     * `$language` for the places that are not rendering to the reader in front
     * of us — an invitation mail goes out in the language of the list's market,
     * not of whoever pressed send.
     */
    public static function current(?string $language = null): string
    {
        return (string) trans('site.lists.default_title', [], $language);
    }

    /**
     * Is this a name we wrote, in any language, rather than one a person chose?
     *
     * Every language rather than the active one: the whole problem being solved
     * here is a title stored under a different locale than the one reading it.
     * Case-insensitive because the comparison is about authorship, not spelling,
     * and an owner who retyped their own title with a capital meant to keep it.
     */
    public static function isOurs(?string $title): bool
    {
        $title = mb_strtolower(trim((string) $title));

        if ($title === '') {
            return false;
        }

        foreach (self::all() as $ours) {
            if ($title === mb_strtolower($ours)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every spelling of the default title, current and retired.
     *
     * @return list<string>
     */
    private static function all(): array
    {
        $translated = array_map(
            fn (string $language): string => (string) trans('site.lists.default_title', [], $language),
            Market::languages(),
        );

        return [...self::RETIRED, ...$translated];
    }
}
