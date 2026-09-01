<?php

declare(strict_types=1);

namespace App\Services\Cove\Writers;

use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\PromptBank;
use App\Services\Editorial\HouseStyle;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The prose for a guide: a title, an intro, how to choose, an FAQ, and a line
 * about each product.
 *
 * Lifted from `GuideBuilder::copy()` with two changes, both of which the fold
 * made possible rather than merely convenient.
 *
 * **It is briefed.** A guide's shortlist used to be chosen by the same class
 * that wrote about it, so there was nothing to tell the writer — the products
 * were whatever the query returned. Now a person can curate the list and say why
 * each product is on it, and `build_instructions` can steer the angle. Both
 * reach the model here, exactly as they already did for a Daily Cove.
 *
 * **It knows about link tokens.** `EditionBuilder` has always handed the model
 * `CoveMarkup::promptContract()`, so a Cove's prose links to products, brands
 * and searches. The guide prompt never did, which is why guide copy is a wall of
 * text with no internal links in a site whose whole argument is comparison. Same
 * contract, same allowlist, same escape-then-allowlist rendering at the far end.
 */
class GuideWriter
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly CoveMarkup $markup,
        private readonly PromptBank $prompts,
    ) {}

    /**
     * @param  list<ProductGroup>  $finds
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     * @param  list<array{id: int, title: string, note: string|null}>  $brief
     */
    public function write(CovePlan $plan, array $finds, array $allowed = [], array $brief = []): Written
    {
        $market = $plan->market;
        $topic = $this->topic($plan);
        $fallback = $this->template($market, $topic, $finds);

        if (! $this->ai->isEnabled()) {
            return $fallback;
        }

        try {
            $response = $this->ai->json(
                $plan->kind->aiFeature(),
                /*
                 * The editable half, then the contract.
                 *
                 * Two contracts, both appended in code and neither
                 * overridable. The link-token one: an edited system prompt that
                 * dropped it would stop every `[[product:…]]` being produced,
                 * and the only symptom would be articles quietly losing their
                 * links. The paragraph one: an article's cards are placed by
                 * the tokens in its own prose, so a prompt that stopped asking
                 * for a paragraph per product would empty the article of
                 * products and push all seven back into the list beneath it.
                 */
                $this->prompts->system('cove.'.$plan->kind->value)
                    ."\n\n".ProseCards::promptContract()
                    .($allowed === [] ? '' : "\n\n".$this->markup->promptContract($allowed)),
                $this->prompt($market, $topic, $finds, $brief, $plan),
                schemaHint: [
                    'title' => '...',
                    'intro' => '...',
                    'how_to_choose' => '...',
                    'faq' => [['q' => '...', 'a' => '...']],
                    'items' => [['verdict' => 'Best for ...', 'copy' => '...']],
                ],
                /*
                 * Raised from 2500. The article now owes a paragraph to every
                 * product on top of the intro, the decisions and the FAQ, and a
                 * response cut off at the ceiling loses the last products
                 * outright — along with the `items` array, which the schema puts
                 * last, so a short budget costs the fallback copy as well as the
                 * writing it was meant to back up.
                 */
                maxTokens: 3500,
            );
        } catch (AiUnavailable $e) {
            Log::info('Guide copy unavailable, using template', ['reason' => $e->getMessage()]);

            return $fallback;
        }

        $items = [];

        foreach ($finds as $index => $group) {
            // Positional: the model is asked for one entry per shortlist row, in
            // the order it was given them.
            $items[] = [
                // `daily_picks.blurb`, printed as a text node under a card.
                // No renderer, so no asterisks.
                'copy' => $this->clean($response['items'][$index]['copy'] ?? null, prose: false),
                'verdict' => $this->clean($response['items'][$index]['verdict'] ?? null, 60, prose: false),
            ];
        }

        return new Written(
            title: $this->clean($response['title'] ?? null, 120, prose: false) ?? $fallback->title,
            intro: $this->clean($response['intro'] ?? null, 400) ?? $fallback->intro,
            /*
             * 6000, not 3000. The body is the article now: the decisions that
             * matter and then a passage per product, where before it was only
             * the decisions. A cut lands mid-paragraph in the last product and
             * takes its link token with it, which loses that product its card
             * as well as its sentences.
             */
            body: $this->clean($response['how_to_choose'] ?? null, 6000),
            faq: $this->faq($response['faq'] ?? null),
            items: $items,
            source: 'ai',
        );
    }

    /**
     * What the guide is about, in the words it will be searched for.
     *
     * The keyphrase leads when there is one: it is the phrase the page is
     * written to answer, where the title is a headline and may be nothing anyone
     * types.
     */
    private function topic(CovePlan $plan): string
    {
        return filled($plan->focus_keyphrase)
            ? (string) $plan->focus_keyphrase
            : (string) $plan->title;
    }

    /**
     * @param  list<ProductGroup>  $finds
     * @param  list<array{id: int, title: string, note: string|null}>  $brief
     */
    private function prompt(
        Market $market,
        string $topic,
        array $finds,
        array $brief,
        CovePlan $plan,
    ): string {
        $curated = [];

        foreach ($brief as $position => $entry) {
            /*
             * The note is the reason a person put this product on the list. It
             * is the most useful sentence the model gets, and the one thing a
             * query result could never supply.
             */
            $curated[] = sprintf(
                '%d. id %d: %s%s',
                $position + 1,
                $entry['id'],
                $entry['title'],
                filled($entry['note'] ?? null) ? ' — why it is here: '.$entry['note'] : '',
            );
        }

        $chosen = array_column($brief, 'id');
        $lines = [];

        foreach ($finds as $index => $group) {
            if (in_array($group->id, $chosen, true)) {
                // Already described above. Listing it twice invites two
                // paragraphs about one product.
                continue;
            }

            // Structured facts only. The model gets what we know and nothing to
            // fill gaps with.
            $lines[] = sprintf(
                '%d. id %d: %s | brand: %s | category: %s | sold by %d shop(s)',
                $index + 1,
                $group->id,
                $group->title,
                $group->brand ?? 'unknown',
                $group->category ?? 'unknown',
                $group->merchant_count,
            );
        }

        /*
         * Named blocks, composed by the template.
         *
         * The editor's direction is one of them, and it goes in the *user*
         * prompt rather than the system message — so it sits underneath the hard
         * rules rather than beside them, and "mention how cheap it is" cannot
         * become permission to.
         *
         * An empty block leaves nothing behind: PromptBank collapses the gap, so
         * a piece with no curated products does not send a prompt with a hole
         * where the shortlist would be.
         */
        return $this->prompts->user('cove.'.$plan->kind->value, [
            'language' => $market->language(),
            'topic' => $topic,
            'title' => (string) $plan->title,

            /*
             * The window, for a seasonal Cove.
             *
             * Named so the prose can say which season it is about without
             * saying when it is being read — the page is published months
             * ahead and stays up through the window, every year.
             */
            'season' => filled($plan->season_from) && filled($plan->season_to)
                ? $plan->season_from.' to '.$plan->season_to.', every year'
                : null,
            'direction' => filled($plan->build_instructions)
                ? "The editor's direction for this piece — follow it within the rules above:\n".trim((string) $plan->build_instructions)
                : null,
            'curated' => $curated === [] ? null : "The curated shortlist, in this order:\n".implode("\n", $curated),
            'finds' => $lines === []
                ? null
                : ($curated === [] ? "Shortlist:\n".implode("\n", $lines) : "Also on the page:\n".implode("\n", $lines)),
        ]);
    }

    /** @param list<ProductGroup> $finds */
    private function template(Market $market, string $topic, array $finds): Written
    {
        return new Written(
            title: __('site.guides.template_title', ['topic' => $topic], $market->language()),
            intro: __('site.guides.template_intro', [
                'topic' => $topic,
                'count' => count($finds),
            ], $market->language()),
            body: null,
            faq: null,
            // No copy at all rather than filler. An empty line under a product
            // is honest; a generated sentence that says nothing is not.
            items: array_map(fn () => ['copy' => null, 'verdict' => null], $finds),
            source: 'template',
        );
    }

    /** @return list<array{q: string, a: string}>|null */
    private function faq(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $faq = [];

        foreach ($raw as $entry) {
            $q = $this->clean($entry['q'] ?? null, 200, prose: false);
            $a = $this->clean($entry['a'] ?? null, 600);

            // Both halves or neither: a half-empty Q&A pair renders as a broken
            // FAQPage and Google will say so.
            if ($q !== null && $a !== null) {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }

        return $faq === [] ? null : $faq;
    }

    /**
     * Model output, made storable.
     *
     * `$prose` says whether the field has a renderer downstream, and it decides
     * what happens to `**bold**`: an intro, a body or an FAQ answer goes
     * through {@see CoveMarkup} and gets `<strong>`, while a title, a verdict
     * or an FAQ question is printed as a React text node and would show the
     * asterisks. See {@see HouseStyle}.
     *
     * House style runs before the limit, not after. Replacing an em dash makes
     * the string two characters longer, so trimming first would let a body come
     * back over its ceiling — and the ceilings here are the ones that keep a
     * `<meta>` description and a `varchar` column honest.
     */
    private function clean(mixed $value, int $limit = 1200, bool $prose = true): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $prose ? HouseStyle::prose($value) : HouseStyle::plain($value);

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
