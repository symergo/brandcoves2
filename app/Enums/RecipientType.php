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
 * Relationships, not genders and not ages. A friend or a colleague has no
 * gender here on purpose: a product an editor would tag "for women" is a
 * stereotype more often than a fact, and where a product genuinely is
 * gendered its title says so. Age is its own vocabulary (`age:teen`), so
 * `recipient:child` means "their child", who may be forty.
 *
 * `recipients.relationship` is free text and stays so; the Whisperer folds
 * what the giver typed onto these values where it can (`SuggestionEngine::
 * recipientFit()`), and an unrecognised relationship simply scores neutral.
 */
enum RecipientType: string
{
    case Partner = 'partner';
    case Mother = 'mother';
    case Father = 'father';
    case Grandparent = 'grandparent';
    case Child = 'child';
    case Friend = 'friend';
    case Colleague = 'colleague';
    case Sibling = 'sibling';
    case Teacher = 'teacher';
    case Host = 'host';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
