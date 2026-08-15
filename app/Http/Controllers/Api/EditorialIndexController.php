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
                'GET  /api/editorial/products?market=&q=&includeLive=1' => 'Also ask the live sources (bol). Slower, and the results come back as ordinary catalogue products with ids.',
                'GET  /api/editorial/products/{id}' => 'One product, with the compliance flags that decide where it may appear.',
                'GET  /api/editorial/topics?market=' => 'Guide topics ripened by what visitors actually searched for.',
                'GET  /api/editorial/coves?market=' => 'The editorial calendar: planned Coves and whether they were built.',
                'POST /api/editorial/coves' => 'Write or rewrite the plan for one date. Creates a draft.',
                'POST /api/editorial/coves/{id}/approve' => 'Approve a plan so the builder will use it.',
                'POST /api/editorial/coves/{id}/build' => 'Queue the build for that date. Idempotent.',
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
            'writing' => [
                'links' => 'Never write a URL, a markdown link or an HTML tag. Link with tokens: [[product:1234|label]], [[brand:Sony]], [[search:draadloze koptelefoon]]. Anything outside the piece\'s own allowlist is stripped to plain text, so a made-up link becomes an unlinked phrase rather than a 404.',
                'products' => 'Only products returned by /products exist. Ids are per market: the same product in another market is a different id with different offers, and mixing them lets a foreign price masquerade as the cheapest.',
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
