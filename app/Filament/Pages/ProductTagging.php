<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Services\Gift\TaggingQueue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The products waiting for gift tags, and a prompt to tag them in Claude.
 *
 * The page never tags anything itself, on the owner's call (2026-09-28): it
 * counts what is waiting, lets a person pick which queue (saved to a wish list,
 * or new in the catalogue), which market and how far back, and writes the
 * prompt to paste into a Claude session in this repository. That session runs
 * the giftcoves-tag-products skill, reads `GET /products/to-tag` and posts
 * over `POST /products/tags`, the same way Coves are written here and
 * published over the API. So no model runs on the server and nothing here
 * spends the site's AI budget. See docs/features/gift-tags.md, "The tagging
 * queue".
 */
class ProductTagging extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Product tagging';

    protected static ?string $title = 'Product tagging';

    protected string $view = 'filament.pages.product-tagging';

    /** A market value, or 'all'. */
    public string $market = 'all';

    public string $source = TaggingQueue::LISTS;

    public int $days = 7;

    /** How far back the page offers, in days. A year covers any backlog worth a prompt. */
    public const DAY_OPTIONS = [1, 7, 30, 90, 365];

    /**
     * Waiting products per market and source.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        $queue = app(TaggingQueue::class);
        $counts = [];

        foreach (Market::cases() as $market) {
            foreach (TaggingQueue::SOURCES as $source) {
                $counts[$market->value][$source] = $queue->count($market, $source, $this->safeDays());
            }
        }

        return $counts;
    }

    /** @return list<string> */
    public function chosenMarkets(): array
    {
        $values = Market::values();

        return in_array($this->market, $values, true) ? [$this->market] : $values;
    }

    /**
     * The prompt, with the counts in it so the session knows when it is done.
     *
     * Markets with nothing waiting are left out, and an empty queue says so
     * instead of producing a prompt that would fetch nothing.
     */
    public function prompt(): ?string
    {
        $counts = $this->counts();
        $source = in_array($this->source, TaggingQueue::SOURCES, true) ? $this->source : TaggingQueue::LISTS;
        $days = $this->safeDays();

        $lines = [];

        foreach ($this->chosenMarkets() as $market) {
            $waiting = $counts[$market][$source] ?? 0;

            if ($waiting > 0) {
                $lines[] = "- {$market}: {$waiting} waiting, GET /products/to-tag?market={$market}&source={$source}&days={$days}";
            }
        }

        if ($lines === []) {
            return null;
        }

        $what = $source === TaggingQueue::LISTS
            ? "products saved to a wish list in the last {$days} day(s)"
            : "giftable products new in the catalogue in the last {$days} day(s)";

        return implode("\n", [
            "Use the giftcoves-tag-products skill to tag {$what} on GiftCoves production.",
            '',
            ...$lines,
            '',
            'Judge every product by the skill\'s brief (interests, recipients, occasions, preference, or not a gift),',
            'post each batch with POST /products/tags, and finish by reporting per market how many were tagged,',
            'how many judged not a gift, and any value you wished the vocabulary had.',
        ]);
    }

    private function safeDays(): int
    {
        return in_array($this->days, self::DAY_OPTIONS, true) ? $this->days : 7;
    }
}
