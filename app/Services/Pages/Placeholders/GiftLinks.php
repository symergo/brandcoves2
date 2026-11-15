<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\GiftLanding;
use App\Models\ProductGroup;
use App\Services\Gift\GiftLandingCopy;
use App\Services\Pages\Context\PageContext;
use Illuminate\Support\Facades\Cache;

/**
 * The gift landing pages these results are tagged for (owner, 2026-11-15:
 * unique content for the search page from what only we know).
 *
 * An editor tagged products with who they suit and what that person loves
 * (`recipient:father`, `interest:coffee`; docs/features/gift-tags.md). This
 * reads those tags off the products on the page and links the landing pages
 * they point at: "Cadeaus voor papa die van koffie houdt". No other shop
 * comparison has the tags, so no other search page has this sentence.
 *
 * ## Which pages
 *
 * An interest counts when at least two products on the page carry it; one
 * tagged product among twenty-four says something about that product, not
 * about the search. Per interest one page: for the recipient most of those
 * same products are tagged for, else the recipient whose page for that
 * interest holds most products. With no interest that counts, a recipient
 * two products share links to that recipient's own page.
 *
 * Only recorded pages (`gift_landings`), so no link can 404. Editors' tags
 * only, not the crowd's: those are read at less weight everywhere else, and
 * a sentence on a search page is a claim.
 *
 * No query per page: the market's landing pages are one small table, read
 * whole and cached ten minutes.
 */
final class GiftLinks implements PlaceholderFunction
{
    private const LIMIT = 3;

    private const MIN_PRODUCTS = 2;

    private const TTL = 600;

    public function name(): string
    {
        return 'gift_links';
    }

    public function label(): string
    {
        return 'Gift pages these results are tagged for, linked';
    }

    public function help(): string
    {
        return 'The "gifts for dad who loves coffee" pages that match the gift tags on these results: an interest at least two products here carry, for the recipient they are tagged for most. At most three. Empty when the results carry no such tags, which hides the block.';
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
        return Value::links([
            ['label' => 'Cadeaus voor papa die van koffie houdt', 'url' => '#'],
            ['label' => 'Cadeaus voor een collega die van koffie houdt', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $interests = [];   // interest => products carrying it
        $recipients = [];  // recipient => products carrying it
        $pairs = [];       // interest => recipient => products carrying both

        foreach ($context->items as $group) {
            if (! $group instanceof ProductGroup) {
                continue;
            }

            $mine = ['interest' => [], 'recipient' => []];

            foreach ($group->giftTags() as $tag) {
                [$vocabulary, $value] = array_pad(explode(':', (string) $tag, 2), 2, '');

                if (isset($mine[$vocabulary]) && $value !== '') {
                    $mine[$vocabulary][$value] = true;
                }
            }

            foreach (array_keys($mine['interest']) as $interest) {
                $interests[$interest] = ($interests[$interest] ?? 0) + 1;

                foreach (array_keys($mine['recipient']) as $recipient) {
                    $pairs[$interest][$recipient] = ($pairs[$interest][$recipient] ?? 0) + 1;
                }
            }

            foreach (array_keys($mine['recipient']) as $recipient) {
                $recipients[$recipient] = ($recipients[$recipient] ?? 0) + 1;
            }
        }

        $interests = array_filter($interests, fn (int $n) => $n >= self::MIN_PRODUCTS);
        $recipients = array_filter($recipients, fn (int $n) => $n >= self::MIN_PRODUCTS);

        if ($interests === [] && $recipients === []) {
            return Value::nothing();
        }

        arsort($interests);
        arsort($recipients);

        $pages = self::pages($context->market);
        $links = [];

        foreach (array_keys($interests) as $interest) {
            $forInterest = $pages[$interest] ?? [];

            if ($forInterest === []) {
                continue;
            }

            // The recipient these same products are tagged for most; else the fullest page.
            $tagged = $pairs[$interest] ?? [];
            arsort($tagged);
            $recipient = null;

            foreach (array_keys($tagged) as $candidate) {
                if (isset($forInterest[$candidate])) {
                    $recipient = $candidate;
                    break;
                }
            }

            $recipient ??= array_key_first($forInterest);
            $links[$forInterest[$recipient]['url']] = $forInterest[$recipient];
        }

        if ($links === []) {
            foreach (array_keys($recipients) as $recipient) {
                if (isset($pages[''][$recipient])) {
                    $links[$pages[''][$recipient]['url']] = $pages[''][$recipient];
                }
            }
        }

        return Value::links(array_slice(array_values($links), 0, self::LIMIT));
    }

    /**
     * The market's landing pages: interest ('' for a recipient's own page) =>
     * recipient => link, the fullest page of each interest first.
     *
     * @return array<string, array<string, array{label: string, url: string}>>
     */
    private static function pages(Market $market): array
    {
        return Cache::remember('gift-links:'.$market->value, self::TTL, function () use ($market): array {
            $pages = [];

            GiftLanding::query()
                ->forMarket($market)
                ->orderByDesc('product_count')
                ->orderBy('id')
                ->get(['recipient', 'interest', 'path'])
                ->each(function (GiftLanding $page) use (&$pages, $market): void {
                    if (! $page->recipient instanceof RecipientType) {
                        return;
                    }

                    $interest = $page->interest instanceof Interest ? $page->interest : null;

                    $pages[$interest?->value ?? ''][$page->recipient->value] = [
                        'label' => (new GiftLandingCopy($market, $page->recipient, $interest))->heading(),
                        'url' => (string) $page->path,
                    ];
                });

            return $pages;
        });
    }
}
