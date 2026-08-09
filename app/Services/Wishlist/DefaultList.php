<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListKind;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;

/**
 * "My wishlist" — the one list every owner has.
 *
 * A one-tap save has to land somewhere, and until now that somewhere was a list
 * called "Saved items" conjured on first use. That is a filing cabinet, not a
 * place: nobody thinks of it as theirs, nobody sends anyone a link to it, and
 * "where did my save go?" had no good answer.
 *
 * One row per owner is marked `is_default`, enforced by a partial unique index
 * rather than by convention — two defaults would make that question
 * unanswerable again, and this is the sort of thing a concurrent double-tap
 * creates.
 */
class DefaultList
{
    /**
     * Titles this application has given the default list, in every language it
     * speaks. Anything else was typed by a person.
     *
     * @var list<string>
     */
    private const OUR_OLD_DEFAULTS = [
        'Saved items',
        'Bewaard',
        'Enregistrés',
        'Guardados',
    ];

    private static function isOurOldDefault(?string $title): bool
    {
        $title = trim((string) $title);

        foreach ([...self::OUR_OLD_DEFAULTS, __('site.lists.default_title')] as $ours) {
            if (mb_strtolower($title) === mb_strtolower((string) $ours)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The owner's standard list, created if this is their first.
     *
     * Adopts an existing `mine` list rather than making a second one, so people
     * who already have "Saved items" keep it — with its items — instead of
     * finding a new empty list beside it.
     */
    public function for(Owner $owner, CurrentMarket $current): Wishlist
    {
        $existing = $owner->scope(Wishlist::query())
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $adopted = $owner->scope(Wishlist::query())
            ->where('market', $current->value())
            ->where('kind', ListKind::Mine->value)
            ->whereNull('handed_over_at')
            ->oldest()
            ->first();

        if ($adopted !== null) {
            $adopted->update([
                'is_default' => true,
                /*
                 * Rename it, but only if it still carries a name we chose.
                 *
                 * Everybody who used the site before this feature has a list
                 * called "Saved items" or "Bewaard", and leaving it that way
                 * means the product calls it a wishlist everywhere except on
                 * the list itself. A title the person typed is theirs and is
                 * never touched.
                 */
                'title' => self::isOurOldDefault($adopted->title)
                    ? __('site.lists.default_title')
                    : $adopted->title,
            ]);

            return $adopted;
        }

        return Wishlist::create([
            ...$owner->attributes(),
            'title' => __('site.lists.default_title'),
            'market' => $current->get(),
            'kind' => ListKind::Mine,
            'is_default' => true,
        ]);
    }
}
