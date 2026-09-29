<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Gift\GiftTags;

/**
 * Who a gift is for, as a type rather than a name.
 *
 * One of the vocabularies a product can be tagged with (see
 * {@see GiftTags}). Closed, and short on purpose: a tag is
 * only useful when two people would pick the same one for the same product,
 * and "for a mother" is that kind of tag where "for a busy professional" is
 * not.
 *
 * ## Split by gender (owner, 2026-09-29)
 *
 * Until 2026-09-29 a friend, a sibling or a grandparent had no gender here, on
 * the grounds that a product tagged "for women" is more often a stereotype
 * than a fact. The owner split them: nobody thinks of their oma as "oma of
 * opa", and the two get different ideas. The combined relations went
 * altogether, "to keep simpler code": one value per relation, compared as it
 * is. A product that suits both carries both tags; the migration that made the
 * split gave every product tagged for a combined relation both of its
 * gendered tags (2026_09_29_000200_relations_split_by_gender).
 *
 * Age is its own vocabulary (`age:13-17`), so `recipient:son` means "their
 * son", who may be forty.
 *
 * `recipients.relationship` is free text and stays so; the Whisperer folds
 * what the giver typed onto these values where it can (`lang/*\/intent.php`),
 * and an unrecognised relationship simply scores neutral.
 */
enum RecipientType: string
{
    case Partner = 'partner';
    case Mother = 'mother';
    case Father = 'father';
    case Grandmother = 'grandmother';
    case Grandfather = 'grandfather';
    case Son = 'son';
    case Daughter = 'daughter';
    case Brother = 'brother';
    case Sister = 'sister';
    case MaleFriend = 'male_friend';
    case FemaleFriend = 'female_friend';
    case Colleague = 'colleague';
    case FemaleTeacher = 'female_teacher';
    case MaleTeacher = 'male_teacher';
    case MaleHost = 'male_host';
    case FemaleHost = 'female_host';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** "mother" as "Mama": the name of the relationship in the current language. */
    public function label(): string
    {
        return (string) __("site.gift.relationships.{$this->value}");
    }

    /**
     * A saved relationship as a person reads it: "mother" as "Mama" in the
     * current language, anything typed by hand as typed, nothing as null.
     *
     * Static and here, because My people, a person's page and the list
     * wizard's person cards all show it, and each must say the same word.
     */
    public static function describe(?string $relationship): ?string
    {
        $relationship = trim((string) $relationship);

        if ($relationship === '') {
            return null;
        }

        return self::tryFrom(mb_strtolower($relationship))?->label() ?? $relationship;
    }

    /**
     * The "who is it to you" picker, as Find a gift and My people both offer
     * it. One list, so the two screens cannot drift apart.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ], self::cases());
    }
}
