<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ebay;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * eBay Marketplace Account Deletion — the compliance webhook.
 *
 * eBay requires every production application to expose an endpoint it can
 * notify when one of *its* users asks for their personal data to be erased. An
 * application without one is marked **non compliant** in the developer portal,
 * and a non-compliant keyset does not mint production tokens — which is exactly
 * the `invalid_client` this integration was stuck on. This endpoint is what
 * turns the eBay credentials on.
 *
 * ## Two jobs, one URL
 *
 * **GET** is the one-off challenge. eBay calls with `?challenge_code=…` and
 * expects `{"challengeResponse": sha256(challengeCode + verificationToken +
 * endpoint)}`. It re-runs whenever the endpoint is saved in the portal.
 *
 * **POST** is a real deletion notification. eBay wants a 2xx, promptly. What it
 * does *not* want is a considered reply — the acknowledgement is the contract,
 * and anything slow or clever here turns into a retry storm and a compliance
 * failure.
 *
 * ## What we actually erase: nothing, and that is a finding rather than a shrug
 *
 * This site stores eBay *listings* — item ids, titles, prices — and holds no
 * eBay user identifier anywhere. `products.external_id` is an item id;
 * `merchants` has one row for eBay itself; `wishlist_items` reference our own
 * groups or a source item id. Nobody signs in here with an eBay account.
 *
 * So a deletion notification names somebody we have never heard of, and there
 * is nothing to delete. That is the honest answer and the endpoint says so by
 * acknowledging without acting. {@see self::erase()} is where the work would go
 * if that ever changed, and it is deliberately a real method with a comment
 * rather than an absence, so the next person adding an eBay sign-in has
 * somewhere obvious to look.
 *
 * @see docs/features/ebay-account-deletion.md
 */
class AccountDeletionController extends Controller
{
    /**
     * eBay's own constraint on the verification token, enforced here too.
     *
     * Checked on our side because the failure is otherwise silent and remote:
     * a token eBay would reject still hashes perfectly well locally, so the
     * challenge succeeds in a test and fails in the portal, and the message
     * there does not mention length.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,80}$/';

    /**
     * The challenge. eBay proves the endpoint belongs to whoever configured it.
     *
     * The hash covers the endpoint URL as well as the code and the token, so
     * the URL below has to be the exact string registered in the portal —
     * `https://giftcoves.com/…` and `https://www.giftcoves.com/…` produce
     * different hashes for an identical request, and eBay reports only that
     * validation failed.
     */
    public function challenge(Request $request): JsonResponse
    {
        $code = (string) $request->query('challenge_code', '');
        $token = (string) config('giftcoves.connectors.ebay.deletion.verification_token');
        $endpoint = $this->endpoint($request);

        if ($code === '') {
            // Not eBay. A browser, a crawler, or somebody checking the URL is
            // alive — answered plainly rather than with a hash of an empty
            // string, which would look like a valid response to a bad request.
            return response()->json(['error' => 'challenge_code is required'], 400);
        }

        if (! $this->tokenIsUsable($token)) {
            /*
             * 503, not 500, and loudly logged.
             *
             * An unconfigured token cannot be answered correctly, and answering
             * *incorrectly* is worse than failing: eBay would record a
             * validation failure against an endpoint that is otherwise fine,
             * and the fix would look like a code problem rather than a missing
             * environment variable.
             */
            Log::warning('eBay account-deletion challenge arrived with no usable verification token', [
                'configured' => $token !== '',
                'endpoint' => $endpoint,
            ]);

            return response()->json([
                'error' => 'The verification token is not configured. Set EBAY_DELETION_VERIFICATION_TOKEN to 32-80 characters of [A-Za-z0-9_-].',
            ], 503);
        }

        /*
         * Order is part of the specification: code, then token, then endpoint.
         *
         * Not an arbitrary concatenation — any other order hashes to something
         * eBay will not accept, and there is no error message that says so.
         */
        return response()->json([
            'challengeResponse' => hash('sha256', $code.$token.$endpoint),
        ])->header('Content-Type', 'application/json');
    }

    /**
     * The notification itself.
     *
     * 204 rather than 200 with a body: eBay accepts either, and there is
     * nothing to say. A body would only be something to get wrong.
     */
    public function notify(Request $request): Response
    {
        $notification = $request->input('notification', []);
        $data = is_array($notification) ? ($notification['data'] ?? []) : [];

        /*
         * The username, user id and EIAS token in this payload are the personal
         * data of somebody who has just asked to be forgotten.
         *
         * So they are NOT logged. Writing them to `laravel.log` — which is
         * retained, shipped and searchable — would create a durable record of a
         * person in direct response to their erasure request, which is the one
         * thing this notification exists to prevent. The notification id is
         * eBay's own reference and identifies nobody; it is enough to prove
         * receipt if eBay ever asks.
         */
        Log::info('eBay account-deletion notification acknowledged', [
            'notificationId' => is_array($notification) ? ($notification['notificationId'] ?? null) : null,
            'topic' => $request->input('metadata.topic'),
        ]);

        $this->erase(is_array($data) ? $data : []);

        return response()->noContent();
    }

    /**
     * Erase what we hold for this eBay user.
     *
     * Which is nothing, today. No table here is keyed by an eBay user
     * identifier: `products.external_id` holds an eBay *item* id, `merchants`
     * holds one row for eBay itself, and nobody authenticates here with an eBay
     * account. A notification therefore names somebody this database has never
     * seen.
     *
     * Kept as a method rather than omitted so the claim is written down where
     * it would have to be changed. If an eBay sign-in, an eBay order import or
     * a seller-side integration ever lands, this is the callback that has to
     * start doing something — and `bc:prune-personal-data` is the shape to
     * follow.
     *
     * @param  array<string, mixed>  $data
     */
    private function erase(array $data): void
    {
        // Intentionally empty. See the docblock: there is nothing keyed to an
        // eBay user in this database.
    }

    /**
     * The endpoint URL, exactly as registered with eBay.
     *
     * Config first, because production serves three hostnames and does not yet
     * redirect between them, so a URL this app generates for itself is a guess
     * — and a wrong guess here fails the challenge rather than merely looking
     * untidy.
     *
     * The request-derived fallback keeps local and staging working without
     * ceremony. It uses the path only from the route, and the scheme and host
     * from the request as Traefik reports them.
     */
    private function endpoint(Request $request): string
    {
        $configured = (string) config('giftcoves.connectors.ebay.deletion.endpoint');

        if (trim($configured) !== '') {
            // Trailing slashes change the hash. Trimmed here rather than
            // trusting whoever pasted the value.
            return rtrim(trim($configured), '/');
        }

        return rtrim($request->url(), '/');
    }

    private function tokenIsUsable(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }
}
