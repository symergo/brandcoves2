<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\GiftLanding;
use App\Services\Search\GiftIntentParser;
use Illuminate\Support\Str;

/**
 * What links to a gift landing page, and what it links to.
 *
 * Every link here points at a recorded page, so none of them can 404:
 * hreflang between markets that **both** have the page (an alternate to a
 * missing page makes a search engine drop the whole cluster, see
 * App\Services\Seo\Alternates), and the sideways links to the same
 * recipient's other interests and the same interest's other recipients.
 */
class GiftLandingLinks
{
    /**
     * The same page in every published market that has it.
     *
     * Paired on the (recipient, interest) pair, not on the path: the path is
     * in each market's own words, `/be-nl/.../papa/koken` and
     * `/be-fr/.../papa/cuisine`, and those are the same page.
     *
     * @return array<string, string> hreflang => absolute URL; empty when only one market has it
     */
    public function alternates(RecipientType $recipient, ?Interest $interest): array
    {
        $alternates = [];

        GiftLanding::query()
            ->where('recipient', $recipient->value)
            ->when($interest === null, fn ($q) => $q->whereNull('interest'), fn ($q) => $q->where('interest', $interest?->value))
            ->orderBy('market')
            ->get(['market', 'path'])
            ->each(function (GiftLanding $page) use (&$alternates): void {
                if ($page->market->isPublished()) {
                    $alternates[$page->market->hrefLang()] = url($page->path);
                }
            });

        return count($alternates) > 1 ? $alternates : [];
    }

    /**
     * Alternates for every page in a market, in one query, for the sitemap.
     *
     * @return array<string, array<string, string>> path => (hreflang => URL)
     */
    public function alternatesFor(Market $market): array
    {
        $byPair = [];

        GiftLanding::query()
            ->orderBy('market')
            ->get(['market', 'recipient', 'interest', 'path'])
            ->each(function (GiftLanding $page) use (&$byPair): void {
                if ($page->market->isPublished()) {
                    $byPair[$page->recipient->value.'|'.($page->interest?->value ?? '')][$page->market->hrefLang()] = url($page->path);
                }
            });

        $out = [];

        foreach (GiftLanding::query()->forMarket($market)->get(['recipient', 'interest', 'path']) as $page) {
            $alternates = $byPair[$page->recipient->value.'|'.($page->interest?->value ?? '')] ?? [];
            $out[$page->path] = count($alternates) > 1 ? $alternates : [];
        }

        return $out;
    }

    /**
     * The recipient's other interests with a page, most products first.
     *
     * @return list<array{label: string, url: string}>
     */
    public function moreFor(Market $market, RecipientType $recipient, ?Interest $except = null, int $limit = 12): array
    {
        return GiftLanding::query()
            ->forMarket($market)
            ->where('recipient', $recipient->value)
            ->whereNotNull('interest')
            ->when($except !== null, fn ($q) => $q->where('interest', '!=', $except?->value))
            ->orderByDesc('product_count')
            ->limit($limit)
            ->get(['interest', 'path'])
            ->map(fn (GiftLanding $page) => [
                'label' => $page->interest->label(),
                'url' => $page->path,
            ])
            ->all();
    }

    /**
     * The same interest's page for other recipients.
     *
     * @return list<array{label: string, url: string}>
     */
    public function sameInterest(Market $market, Interest $interest, RecipientType $except): array
    {
        return GiftLanding::query()
            ->forMarket($market)
            ->where('interest', $interest->value)
            ->where('recipient', '!=', $except->value)
            ->orderByDesc('product_count')
            ->get(['recipient', 'path'])
            ->map(fn (GiftLanding $page) => [
                'label' => Str::ucfirst((string) trans("site.gift_landing.recipients.{$page->recipient->value}.name", [], $market->language())),
                'url' => $page->path,
            ])
            ->all();
    }

    /**
     * The landing page closest to a Gift Finder brief, for "Open as a page".
     *
     * The Finder's answers are a POST, so its results cannot be linked to or
     * bookmarked; a landing page can. The first of the brief's interests with
     * a page for this recipient wins, then the recipient's own page. The
     * budget travels as `?budget=`. Null when the brief names nobody the
     * vocabulary knows or no page exists: the button is not shown rather than
     * leading somewhere that answers a different question.
     *
     * The relationship on a saved person is free text ("mama", "my brother"),
     * so it is read by the same word lists as the search box when it is not
     * a vocabulary value already.
     */
    public function pageFor(TasteBrief $brief): ?string
    {
        $recipient = $this->recipientOf($brief);

        if ($recipient === null) {
            return null;
        }

        $budget = $brief->budgetMin === null && $brief->budgetMax === null ? null : [$brief->budgetMin, $brief->budgetMax];

        foreach ($brief->interests as $value) {
            $interest = Interest::tryFrom(mb_strtolower(trim($value)));

            if ($interest !== null && GiftLanding::lookup($brief->market, $recipient, $interest) !== null) {
                return BriefUrl::path($brief->market, $recipient, $interest, $budget);
            }
        }

        return GiftLanding::lookup($brief->market, $recipient, null) === null
            ? null
            : BriefUrl::path($brief->market, $recipient, null, $budget);
    }

    private function recipientOf(TasteBrief $brief): ?RecipientType
    {
        $relationship = trim((string) $brief->relationship);

        if ($relationship === '') {
            return null;
        }

        $known = RecipientType::tryFrom(mb_strtolower($relationship));

        if ($known !== null) {
            return $known;
        }

        $read = app(GiftIntentParser::class)->parse($relationship, $brief->market, giftContext: true)->relationship;

        return $read === null ? null : RecipientType::tryFrom($read);
    }

    /**
     * The recipient's own page, when it exists.
     */
    public function hub(Market $market, RecipientType $recipient): ?string
    {
        return GiftLanding::lookup($market, $recipient, null)?->path;
    }
}
