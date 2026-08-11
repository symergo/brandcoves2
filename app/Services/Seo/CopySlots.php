<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * The registry of editable copy slots.
 *
 * One entry per position in a page's argument. It is the single place that knows
 * three things, and they have to agree:
 *
 *  1. **Which slots exist** — so admin can list them without a developer adding
 *     a form field, and so an orphaned row (a slot the code stopped asking for)
 *     is visible rather than silently dead.
 *  2. **Which placeholders each may contain** — the safety property. A typo'd
 *     `:cont` renders literally to a reader, and worse, a placeholder in the
 *     wrong slot makes a claim the page cannot back: `:percent` in a sentence
 *     that renders even when nothing is discounted asserts a 0% saving. The
 *     admin form validates against this list and refuses the save.
 *  3. **When the slot renders** — shown to the editor, because a sentence that
 *     only appears "when at least one product is reduced" reads very differently
 *     from one that always appears, and an editor cannot know that from the
 *     sentence alone.
 *
 * The translation namespace is here too, because that is where `CopyBank` falls
 * back to when a slot has no enabled variant. Keeping the mapping next to the
 * slot list is what stops the two drifting into a slot whose fallback silently
 * resolves to nothing.
 */
final class CopySlots
{
    /**
     * Surfaces, in the order an editor thinks about them.
     *
     * @return array<string, array{label: string, namespace: string}>
     */
    public static function surfaces(): array
    {
        return [
            'search' => [
                'label' => 'Search results — long copy',
                'namespace' => 'site.narrative',
            ],
            'brand' => [
                'label' => 'Brand page — long copy',
                'namespace' => 'site.brand_narrative',
            ],
        ];
    }

    /**
     * Placeholders shared by every slot on a surface.
     *
     * @return list<string>
     */
    private static function common(string $surface): array
    {
        return $surface === 'search'
            ? ['term', 'count', 'shown', 'shops']
            : ['brand', 'count', 'shown', 'shops'];
    }

    /**
     * Every slot, keyed `surface.slot`.
     *
     * `guard` is the condition under which the slot is rendered at all. Where it
     * says "always", a placeholder that can be zero must not appear — which is
     * why the placeholder lists differ between siblings that look alike.
     *
     * @return array<string, array{surface: string, slot: string, label: string, guard: string, placeholders: list<string>}>
     */
    public static function all(): array
    {
        $slots = [];
        $group = 'General';

        // Groups are declared positionally, so the registry reads in the same
        // order as the page and the editor can be generated straight from it.
        $section = function (string $name) use (&$group): void {
            $group = $name;
        };

        $define = function (string $surface, string $slot, string $label, string $guard, array $extra = []) use (&$slots, &$group): void {
            $slots["{$surface}.{$slot}"] = [
                'surface' => $surface,
                'slot' => $slot,
                'group' => $group,
                'label' => $label,
                'guard' => $guard,
                'placeholders' => array_values(array_unique([...self::common($surface), ...$extra])),
            ];
        };

        /*
         * Search results — long copy.
         */
        $section('Comparing');
        $define('search', 'compare_heading', 'Comparing — heading', 'Always');
        $define('search', 'compare_1', 'Comparing — one card per product', 'Always');
        $define('search', 'compare_2', 'Comparing — how many are comparable', 'Only when at least one product is sold by more than one shop', ['comparable']);
        $define('search', 'compare_3', 'Comparing — where the offers come from', 'Always');

        $section('Prices');
        $define('search', 'prices_heading', 'Prices — heading', 'Always');
        $define('search', 'prices_1', 'Prices — the range on this page', 'Only when at least one product has a price', ['low', 'high']);
        $define('search', 'prices_2', 'Prices — what a discount badge means', 'Always');
        $define('search', 'prices_3', 'Prices — how many are reduced', 'Only when at least one product is below its 30-day median', ['reduced', 'percent']);

        $section('Choosing');
        $define('search', 'choosing_heading', 'Choosing — heading', 'Always');
        $define('search', 'choosing_1', 'Choosing — read the offer count first', 'Always');
        $define('search', 'choosing_2', 'Choosing — stock and price history', 'Always');
        $define('search', 'choosing_3', 'Choosing — brands present', 'Only when the results carry a brand', ['brands']);

        $section('Questions');
        $define('search', 'faq_price_q', 'FAQ — "how much does it cost" question', 'Only when at least one product has a price', ['low', 'high']);
        $define('search', 'faq_price_a', 'FAQ — price answer', 'Only when at least one product has a price', ['low', 'high']);
        $define('search', 'faq_where_q', 'FAQ — "where can I buy" question', 'Always');
        $define('search', 'faq_where_a', 'FAQ — where answer', 'Always');
        $define('search', 'faq_fresh_q', 'FAQ — "how current" question', 'Always');
        $define('search', 'faq_fresh_a', 'FAQ — freshness answer', 'Always');

        /*
         * Brand page — long copy.
         *
         * There was a third surface here, `brand_intro`: ten slots of templated
         * statistics that opened every brand page, plus four rotating variants of
         * its lead sentence. The page stopped rendering them on 2026-08-10 — the
         * term links replaced them — and a surface an editor can rewrite with no
         * effect anywhere is worse than no surface at all, so it is gone rather
         * than left listed. See docs/features/brand-pages.md.
         */
        $section('Comparing');
        $define('brand', 'about_heading', 'About the brand — heading', 'Always');
        $define('brand', 'about_1', 'About — what it makes here', 'Only when we know which categories the brand appears in', ['categories']);
        $define('brand', 'about_2', 'About — its main category', 'Only when we know the leading category', ['category']);
        $define('brand', 'about_3', 'About — where this page comes from', 'Always');

        $section('Prices');
        $define('brand', 'stocked_heading', 'Where it is sold — heading', 'Always');
        $define('brand', 'stocked_1', 'Where — the leading shop', 'Only when we know the leading shop', ['shop']);
        $define('brand', 'stocked_2', 'Where — how many are comparable', 'Only when at least one product is sold by more than one shop', ['comparable']);

        $section('Choosing');
        $define('brand', 'choosing_heading', 'Choosing — heading', 'Always');
        $define('brand', 'choosing_1', 'Choosing — offer count before price', 'Always');
        $define('brand', 'choosing_2', 'Choosing — where the range sits', 'Only when at least one product has a price', ['low', 'high']);
        $define('brand', 'choosing_3', 'Choosing — the product page', 'Always');

        $section('Questions');
        $define('brand', 'faq_price_q', 'FAQ — "how much" question', 'Only when at least one product has a price', ['low', 'high']);
        $define('brand', 'faq_price_a', 'FAQ — price answer', 'Only when at least one product has a price', ['low', 'high']);
        $define('brand', 'faq_where_q', 'FAQ — "which shops" question', 'Always');
        $define('brand', 'faq_where_a', 'FAQ — shops answer', 'Always');
        $define('brand', 'faq_discount_q', 'FAQ — "on offer" question', 'Only when something is genuinely reduced', ['reduced', 'percent']);
        $define('brand', 'faq_discount_a', 'FAQ — offer answer', 'Only when something is genuinely reduced', ['reduced', 'percent']);

        return $slots;
    }

    /** @return array<string, array{surface: string, slot: string, label: string, guard: string, placeholders: list<string>}> */
    public static function forSurface(string $surface): array
    {
        return array_filter(self::all(), fn (array $s) => $s['surface'] === $surface);
    }

    /** @return array{surface: string, slot: string, label: string, guard: string, placeholders: list<string>}|null */
    public static function find(string $surface, string $slot): ?array
    {
        return self::all()["{$surface}.{$slot}"] ?? null;
    }

    public static function namespaceFor(string $surface): ?string
    {
        return self::surfaces()[$surface]['namespace'] ?? null;
    }

    /**
     * Placeholders a body uses, whether or not they are allowed.
     *
     * Matches `:name` but not a bare colon or a time like `09:15`, because a
     * false positive here becomes a validation error an editor cannot explain.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $body): array
    {
        preg_match_all('/:([a-z][a-z0-9_]*)/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Placeholders in a body that this slot does not permit.
     *
     * @return list<string>
     */
    public static function disallowedIn(string $surface, string $slot, string $body): array
    {
        $definition = self::find($surface, $slot);

        if ($definition === null) {
            return [];
        }

        return array_values(array_diff(self::placeholdersIn($body), $definition['placeholders']));
    }
}
