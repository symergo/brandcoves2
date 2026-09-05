<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this API is, for whoever just authenticated.
 *
 * A machine client cannot read docs/features/editorial-api.md, and a client
 * that has to be told the endpoint list out of band drifts from the server the
 * first time either changes. So the root describes itself: the abilities this
 * key actually holds, the markets that exist, and the shape of the writing
 * contract. One call and a writer knows what it may do.
 */
class EditorialIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = AuthenticateApiToken::from($request);

        return response()->json([
            'service' => 'GiftCoves editorial API',
            'token' => [
                'name' => $token?->name,
                'abilities' => $token?->abilities ?? [],
                'expiresAt' => $token?->expires_at?->toIso8601String(),
            ],
            'markets' => array_map(
                fn (Market $m) => [
                    'market' => $m->value,
                    'language' => $m->language(),
                    'label' => $m->label(),
                ],
                Market::cases(),
            ),
            /*
             * Where products can come from, and what each one costs an author
             * in constraints.
             *
             * Reported rather than documented because it changes: bol is live
             * and needs an explicit flag, Amazon is not connected at all, and a
             * writer that assumes otherwise produces an article about products
             * this site cannot show.
             */
            'sources' => $this->sources(),

            'endpoints' => [
                'GET  /api/editorial/products?market=&q=' => 'Find real products to write about. Every id you use must come from here.',
                'GET  /api/editorial/products?market=&ean=' => 'Resolve a barcode to the product in that market. A 422 means the barcode failed its check digit — a misread, not a product we do not carry.',
                'GET  /api/editorial/products?market=&q=&includeLive=1' => 'Also ask the live sources (bol). Slower, and the results come back as ordinary catalogue products with ids.',
                'GET  /api/editorial/products/{id}' => 'One product, with the compliance flags that decide where it may appear.',
                'GET  /api/editorial/topics?market=' => 'Guide topics ripened by what visitors actually searched for.',
                'GET  /api/editorial/coves?market=&kind=' => 'The editorial calendar: planned Coves of every kind, and whether they were built.',
                'POST /api/editorial/coves/drafts' => 'Ask for N draft plans of one kind, from the sources that know what is worth writing here: the observance calendar, the mined topic queue, the gift-wizard interests. Each arrives with a shortlist of real products. Start here rather than inventing titles.',
                'GET  /api/editorial/coves/queue?market=&kinds[]=' => 'The Coves that need prose, each with its shortlist and its link allowlist. One call per writing run.',
                'GET  /api/editorial/coves/{id}/brief' => 'The prompt this Cove would be written from — the assembled system and user messages the builder itself would send, including any edit made in the admin panel, plus the allowlist, the shortlist, the product floor and a revision you can quote back. Prefer it over any copy of the rules.',
                'POST /api/editorial/coves' => 'Write or rewrite one plan whole — kind, address, shortlist and all. Creates a draft.',
                'POST /api/editorial/coves/{id}/editorial' => 'Send the prose back for one plan. Cannot touch the shortlist, and needs the revision from the queue.',
                'POST /api/editorial/coves/{id}/approve' => 'Approve a plan so the builder will use it. Pass build=1 to queue the build in the same call.',
                'POST /api/editorial/coves/{id}/build' => 'Queue the build for that plan, whatever kind it is. Idempotent.',
                'GET  /api/editorial/editions/{market}/{date}' => 'Read back what was actually published, links included.',
                'GET  /api/editorial/guides?market=' => 'Buying guides, drafts included.',
                'POST /api/editorial/guides' => 'Write or rewrite a guide and its ranked items.',
                'POST /api/editorial/guides/{id}/publish' => 'Publish a guide.',
            ],
            /*
             * The writing contract, stated where the writer will see it.
             *
             * Repeated here rather than left in the docs because these three
             * rules are the difference between an article that renders and one
             * that renders with holes in it, and a client that never reads them
             * will break all three on its first attempt.
             */
            /*
             * The order the calls go in.
             *
             * Listed because an endpoint list is not a loop: the first thing a
             * scheduled writer needs to know is that it should ask for ideas
             * rather than invent them, and that is a fact about sequence, not
             * about any one route.
             */
            'loop' => [
                '1. draft' => 'POST /coves/drafts — ask for ideas. Skip it if you already know what you want to write and POST /coves instead.',
                '2. read' => 'GET /coves/queue — the briefs, with products, notes and the link allowlist for each.',
                '3. write' => 'POST /coves/{id}/editorial — the prose, quoting the revision you were given.',
                '4. publish' => 'POST /coves/{id}/approve with build=1 — needs the editorial.publish ability. Without it, a person approves in the admin panel and that is the intended shape.',
            ],

            /*
             * Orientation, not the contract.
             *
             * These four lines were the contract, hand-copied here and into two
             * docs and a skill — and they had drifted: this block omitted the
             * one-paragraph-per-product rule that `ProseCards` exists to make
             * undroppable, so a client following the server's own description of
             * itself wrote prose that publishes with bare cards at the foot of
             * the page. The authoritative version is now served per plan by
             * `GET /coves/{id}/brief`, assembled by the same code the builder
             * uses. `paragraphs` is restated here anyway, because the one thing
             * worse than a second copy is a second copy missing the rule that
             * decides whether the page renders.
             */
            'writing' => [
                'authority' => 'These lines orient you. The contract for a specific Cove is GET /coves/{id}/brief, which returns the exact prompt the builder would use — including any edit made in the admin panel. Prefer it.',
                'paragraphs' => 'Write about EVERY product on the shortlist, each in its own paragraph, naming it with its link token. Its card is rendered directly under the paragraph that names it, so a product no paragraph names gets no writing at all and drops to the foot of the page as a bare card. One product per paragraph.',
                'links' => 'Never write a URL, a markdown link or an HTML tag. Link with tokens: [[product:1234|label]], [[brand:Sony]], [[search:draadloze koptelefoon]]. Anything outside the piece\'s own allowlist is stripped to plain text, so a made-up link becomes an unlinked phrase rather than a 404. A product token must carry a label — [[product:1234]] alone renders as a number in your sentence.',
                'products' => 'Only products returned by /products exist. Ids are per market and per environment: the same product elsewhere is a different id with different offers, and mixing them lets a foreign price masquerade as the cheapest. A barcode is the same number everywhere — prefer /products?ean=.',
                'prices' => 'Never state a price, a rating or a stock claim in prose. Prices move and the page renders live ones; a number in a sentence is wrong within a week.',
            ],
        ]);
    }

    /**
     * The state of each product source, from config rather than from memory.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sources(): array
    {
        $bol = (bool) config('giftcoves.connectors.bol.enabled')
            && filled(config('giftcoves.connectors.bol.client_id'));

        $ebay = (bool) config('giftcoves.connectors.ebay.enabled')
            && filled(config('giftcoves.connectors.ebay.client_id'));

        $tradedoubler = (bool) config('giftcoves.connectors.tradedoubler.enabled')
            && filled(config('giftcoves.connectors.tradedoubler.token'));

        return [
            'awin' => [
                'available' => true,
                'how' => 'Ingested into the catalogue. The default, and what /products returns without any flag.',
            ],
            'bol' => [
                'available' => $bol,
                'how' => $bol
                    ? 'Live. Pass includeLive=1 on /products and matching offers are ingested and grouped in that request, so they come back with real ids and an affiliate link.'
                    : 'Configured off in this environment. includeLive=1 will return catalogue results only.',
            ],
            /*
             * The one source worth a writer going out of their way for, and the
             * reason is the barcode.
             *
             * Tradedoubler returns several advertisers' offers for one product
             * WITH an EAN on it, so its results resolve to product groups and
             * arrive as a genuine multi-shop comparison. That is exactly the
             * shape a buying guide wants to cite and the shape eBay cannot give.
             */
            'tradedoubler' => [
                'available' => $tradedoubler,
                'how' => $tradedoubler
                    ? 'Live, via includeLive=1. A network: one product comes back with prices from several shops attached, usually with an EAN, so results group properly and can be cited by barcode.'
                    : 'Configured off in this environment. includeLive=1 will not reach Tradedoubler.',
            ],
            /*
             * The caveat here is about *what* comes back, not whether anything
             * does — a writer who does not know it will assume eBay is broken.
             *
             * eBay's search results carry no barcode, so most of them cannot be
             * matched to a product group and cannot be cited by EAN the way a
             * Coolblue or bol product can. They are listings, and a listing
             * ends. Write about an eBay find as a lead, never as the anchor
             * product of a Cove that has to still make sense next month.
             */
            'ebay' => [
                'available' => $ebay,
                'how' => $ebay
                    ? 'Live, like bol, and included by the same includeLive=1 flag. Fixed-price new listings only. Results usually have no barcode, so they often will not resolve to a product group — do not build a Cove around one.'
                    : 'Configured off in this environment. includeLive=1 will not reach eBay.',
            ],
            /*
             * Stated plainly because the alternative is a writer trying, getting
             * nothing, and quietly writing about something else.
             *
             * There is no Amazon connector in this codebase — only the config
             * keys, the AmazonProduct decision table and the compliance rules.
             * The blocker is not the editorial side: Amazon forbids mirroring
             * title, price, image and availability, so an Amazon product cannot
             * appear at all until something can re-fetch those live, and that
             * needs PA-API credentials.
             */
            'amazon' => [
                'available' => false,
                'how' => 'Not connected. Amazon products cannot be looked up or written about yet: their terms forbid storing title, price, image and availability, so they must be re-fetched live at render, and no PA-API client exists. Do not write articles that assume an Amazon product page — write about how to shop on Amazon instead, which needs no product data.',
            ],
        ];
    }
}
