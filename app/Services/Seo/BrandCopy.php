<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\BrandStat;
use Illuminate\Support\Number;

/**
 * The prose on a brand page.
 *
 * ## The rule that makes this worth doing
 *
 * **Every sentence is a fact the page can back up.** "Looking for Sony? Coolblue
 * currently has 14 Sony products reduced" is only ever emitted when
 * `top_merchant_id` is Coolblue and `discounted_count` is 14. Nothing here is
 * written unless the number behind it exists.
 *
 * That constraint is not primarily an ethical one, though it is that too. It is
 * what separates this from the generated brand pages every affiliate site has
 * had since 2009 — "Looking for Sony? We have a wide range of Sony products at
 * great prices!" — which rank for a fortnight and then not at all, and which
 * drag the whole domain down when a helpful-content update decides the site is
 * mostly filler. A page that states real, checkable, changing numbers about a
 * live catalogue is not filler, even when the sentence shapes are templates.
 *
 * ## Why templates rather than AI
 *
 * These pages number in the thousands and their numbers change nightly. Writing
 * them with a model would mean either regenerating thousands of pages a night —
 * unaffordable, and forbidden from a request path by the AI invariant — or
 * letting the prose drift out of sync with the prices it quotes, which is the
 * worse failure. So the *facts* are templated and the *creative* layer sits
 * above them: an AI-written Cove that mentions the brand is linked from here,
 * and that is where personality belongs.
 *
 * ## Why the shapes rotate
 *
 * Thousands of pages opening with the identical sentence is a pattern a crawler
 * can see in one sample. The variant is chosen by a hash of the brand, so it is
 * stable — the page does not rewrite itself between two crawls, which would look
 * like instability rather than variety.
 */
class BrandCopy
{
    /**
     * How many opening variants exist per language.
     *
     * Must match the number of `brand.lead_*` keys. A mismatch shows a raw
     * translation key to a reader, so `BrandCopyTest` asserts the count.
     */
    public const LEAD_VARIANTS = 4;

    /**
     * @return array{lead: string, paragraphs: list<string>}
     */
    public function forBrand(BrandStat $stat, Market $market): array
    {
        $brand = $stat->brand;

        return [
            'lead' => $this->lead($stat, $market),
            'paragraphs' => array_values(array_filter([
                $this->availability($stat, $market),
                $this->prices($stat, $market),
                $this->discounts($stat, $market),
                $this->comparison($stat, $market),
            ])),
        ];
    }

    /**
     * The opening line.
     *
     * Deliberately the only sentence that does not depend on a fact beyond the
     * product count, because it is the one sentence that must always exist.
     */
    private function lead(BrandStat $stat, Market $market): string
    {
        // Stable variant per brand: a page that reworded itself between crawls
        // would read as instability rather than variety.
        $variant = (hexdec(substr(hash('sha256', $stat->brand), 0, 8)) % self::LEAD_VARIANTS) + 1;

        return $this->line("lead_{$variant}", $market, [
            'brand' => $stat->brand,
            'count' => Number::format($stat->product_count, locale: $market->hrefLang()),
        ]);
    }

    /** Where you can actually buy it. */
    private function availability(BrandStat $stat, Market $market): ?string
    {
        if ($stat->merchant_count < 1) {
            return null;
        }

        $merchant = $stat->topMerchant?->name;

        // Naming a shop is worth more than counting them, but only if we know
        // which shop. Without the join the honest sentence is the vaguer one.
        if ($merchant !== null && $stat->merchant_count > 1) {
            return $this->line('shops_named', $market, [
                'brand' => $stat->brand,
                'shop' => $merchant,
                'count' => $stat->merchant_count,
            ]);
        }

        return $this->line('shops_count', $market, [
            'brand' => $stat->brand,
            'count' => $stat->merchant_count,
        ]);
    }

    private function prices(BrandStat $stat, Market $market): ?string
    {
        if ($stat->min_price === null) {
            return null;
        }

        // One price, not a range, when the range would be a single point — "from
        // €19 to €19" is the kind of sentence that tells a reader nobody looked.
        if ($stat->max_price === null || $stat->max_price <= $stat->min_price) {
            return $this->line('price_from', $market, [
                'brand' => $stat->brand,
                'low' => $this->money($stat->min_price, $market),
            ]);
        }

        // Two keys rather than one with an optional placeholder: an unfilled
        // `:category` renders as the literal token, and a reader seeing
        // ":category" learns more about our template engine than we would like.
        return $stat->top_category === null
            ? $this->line('price_range', $market, [
                'brand' => $stat->brand,
                'low' => $this->money($stat->min_price, $market),
                'high' => $this->money($stat->max_price, $market),
            ])
            : $this->line('price_range_category', $market, [
                'brand' => $stat->brand,
                'low' => $this->money($stat->min_price, $market),
                'high' => $this->money($stat->max_price, $market),
                'category' => $stat->top_category,
            ]);
    }

    /**
     * The strongest sentence available, and the one most likely to be a lie if
     * written carelessly.
     *
     * Emitted only when something actually is reduced *right now*, measured
     * against our own 30-day median rather than a merchant's claimed "was"
     * price. A shop's strikethrough figure is marketing; the median is evidence.
     */
    private function discounts(BrandStat $stat, Market $market): ?string
    {
        if ($stat->discounted_count < 1) {
            return null;
        }

        $merchant = $stat->topMerchant?->name;

        if ($merchant !== null && $stat->best_discount_percent !== null) {
            return $this->line('discount_named', $market, [
                'brand' => $stat->brand,
                'shop' => $merchant,
                'count' => $stat->discounted_count,
                'percent' => $stat->best_discount_percent,
            ]);
        }

        return $this->line('discount_count', $market, [
            'brand' => $stat->brand,
            'count' => $stat->discounted_count,
        ]);
    }

    /**
     * What the page is actually for.
     *
     * Worth saying explicitly on every brand page, because it is the one claim
     * that distinguishes this from a shop's own brand listing: the prices here
     * come from several shops at once.
     */
    private function comparison(BrandStat $stat, Market $market): ?string
    {
        if ($stat->merchant_count < 2) {
            return null;
        }

        return $this->line('comparison', $market, ['brand' => $stat->brand]);
    }

    /** @param array<string, mixed> $replace */
    private function line(string $key, Market $market, array $replace): string
    {
        return __("site.brand.{$key}", array_filter($replace, fn ($v) => $v !== null), $market->language());
    }

    private function money(int $cents, Market $market): string
    {
        return Number::currency($cents / 100, $market->currency(), $market->hrefLang());
    }
}
