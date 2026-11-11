<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Services\Pages\Context\PageContext;

/**
 * The kinds of product `:other_brands_by_category` lists brands for, as words
 * in a sentence: "autospeaker, constructiespeelgoed en midi controller"
 * (owner, 2026-11-11: under "Andere merken", add "Welke andere merken verkopen
 * <categorieën>?").
 *
 * Read from that placeholder's own answer rather than from the results, so
 * the question never names a kind of product the list under it has no line
 * for, and is empty (hiding its paragraph) exactly when the list is. The
 * context memoises the answer, so asking it here costs nothing more.
 */
final class OtherBrandCategories implements PlaceholderFunction
{
    private const AND = ['nl' => 'en', 'fr' => 'et', 'en' => 'and', 'es' => 'y'];

    public function name(): string
    {
        return 'other_brand_categories';
    }

    public function label(): string
    {
        return 'The kinds of product the other-brands list covers, as words';
    }

    public function help(): string
    {
        return 'The labels of :other_brands_by_category in a sentence: "autospeaker, constructiespeelgoed en midi controller". Empty when that list is, which hides the block.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::text('koptelefoon en speaker');
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $groups = $context->resolve(PlaceholderRegistry::find('other_brands_by_category') ?? new OtherBrandsByCategory);

        if ($groups->type !== Value::GROUPS) {
            return Value::nothing();
        }

        $words = array_map(fn (array $group): string => self::inSentence((string) $group['label']), $groups->items);
        $last = array_pop($words);

        if ($words === []) {
            return Value::text($last);
        }

        $and = self::AND[$context->market->language()] ?? 'en';

        return Value::text(implode(', ', $words)." {$and} {$last}");
    }

    /** "Autospeaker" reads "autospeaker" mid-sentence; "TV" and "USB-kabel" keep their capitals. */
    private static function inSentence(string $label): string
    {
        $second = mb_substr($label, 1, 1);

        return $second !== '' && mb_strtolower($second) === $second
            ? mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1)
            : $label;
    }
}
