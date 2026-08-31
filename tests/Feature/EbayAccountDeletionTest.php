<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay's Marketplace Account Deletion webhook.
 *
 * The stakes are unusual for a webhook: eBay marks an application that does not
 * answer this correctly as **non compliant**, and a non-compliant keyset does
 * not mint production tokens. So a bug here does not degrade a feature, it
 * switches the entire eBay integration off — which is why the challenge hash
 * and its exact inputs are pinned below rather than merely exercised.
 */
class EbayAccountDeletionTest extends TestCase
{
    private const TOKEN = 'EraLREQUdCDlQAHcuu17I_9suRCaVVh2Zy0jNZ2T7XcyCcCz';

    private const ENDPOINT = 'https://giftcoves.com/webhooks/ebay/account-deletion';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.ebay.deletion.verification_token' => self::TOKEN,
            'giftcoves.connectors.ebay.deletion.endpoint' => self::ENDPOINT,
        ]);
    }

    #[Test]
    public function it_answers_the_challenge_with_the_hash_ebay_expects(): void
    {
        $code = 'abc123challenge';

        $response = $this->getJson('/webhooks/ebay/account-deletion?challenge_code='.$code);

        /*
         * The hash is computed here from the specification's own recipe rather
         * than from the controller's, so the test disagrees with the code if
         * the code drifts.
         *
         * Order is part of the specification: code, then token, then endpoint.
         * Any other order produces a perfectly valid-looking hex string that
         * eBay rejects, and the portal's failure message names none of the
         * three inputs.
         */
        $response->assertOk()->assertExactJson([
            'challengeResponse' => hash('sha256', $code.self::TOKEN.self::ENDPOINT),
        ]);
    }

    #[Test]
    public function the_registered_endpoint_url_is_part_of_the_hash(): void
    {
        $code = 'abc123challenge';

        $first = $this->getJson('/webhooks/ebay/account-deletion?challenge_code='.$code)
            ->json('challengeResponse');

        // The same request, the same token, a different registered host.
        config(['giftcoves.connectors.ebay.deletion.endpoint' => 'https://www.giftcoves.com/webhooks/ebay/account-deletion']);

        $second = $this->getJson('/webhooks/ebay/account-deletion?challenge_code='.$code)
            ->json('challengeResponse');

        /*
         * This is the failure that actually happens, and it is why the endpoint
         * is explicit config rather than route().
         *
         * Production serves giftcoves.com, www.giftcoves.com and
         * brandcoves.com without redirecting between them, so a URL the app
         * generates for itself is a guess — and a wrong guess fails validation
         * with an error that looks like a code problem rather than a config one.
         */
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function a_trailing_slash_does_not_change_the_answer(): void
    {
        config(['giftcoves.connectors.ebay.deletion.endpoint' => self::ENDPOINT.'/']);

        // Whoever pastes the URL should not be able to break the hash with a
        // keystroke that means nothing.
        $this->getJson('/webhooks/ebay/account-deletion?challenge_code=xyz')
            ->assertOk()
            ->assertExactJson([
                'challengeResponse' => hash('sha256', 'xyz'.self::TOKEN.self::ENDPOINT),
            ]);
    }

    #[Test]
    public function it_falls_back_to_the_request_url_when_no_endpoint_is_configured(): void
    {
        config(['giftcoves.connectors.ebay.deletion.endpoint' => null]);

        // Keeps local and staging working without ceremony. Production sets the
        // value; everywhere else the URL eBay called is the URL to hash.
        $this->getJson('http://localhost/webhooks/ebay/account-deletion?challenge_code=xyz')
            ->assertOk()
            ->assertExactJson([
                'challengeResponse' => hash('sha256', 'xyz'.self::TOKEN.'http://localhost/webhooks/ebay/account-deletion'),
            ]);
    }

    #[Test]
    public function a_missing_challenge_code_is_a_bad_request_not_a_hash(): void
    {
        // A browser, a crawler, or somebody checking the URL is alive. Hashing
        // an empty string would hand back something that looks like a valid
        // answer to a request that was not one.
        $this->getJson('/webhooks/ebay/account-deletion')->assertStatus(400);
    }

    #[Test]
    public function an_unconfigured_token_refuses_rather_than_answering_wrongly(): void
    {
        config(['giftcoves.connectors.ebay.deletion.verification_token' => null]);

        /*
         * Answering incorrectly is worse than failing.
         *
         * A wrong hash records a validation failure against an endpoint that is
         * otherwise fine, and the fix then looks like a code problem rather
         * than a missing environment variable.
         */
        $this->getJson('/webhooks/ebay/account-deletion?challenge_code=xyz')->assertStatus(503);
    }

    #[Test]
    public function a_token_ebay_would_reject_is_refused_here_too(): void
    {
        // 31 characters: one short of eBay's minimum, and otherwise perfectly
        // usable. It hashes fine locally and fails in the portal, where the
        // message says nothing about length — so the check belongs on our side.
        config(['giftcoves.connectors.ebay.deletion.verification_token' => str_repeat('a', 31)]);

        $this->getJson('/webhooks/ebay/account-deletion?challenge_code=xyz')->assertStatus(503);

        // And a character outside eBay's alphabet.
        config(['giftcoves.connectors.ebay.deletion.verification_token' => str_repeat('a', 40).'!']);

        $this->getJson('/webhooks/ebay/account-deletion?challenge_code=xyz')->assertStatus(503);
    }

    #[Test]
    public function it_acknowledges_a_deletion_notification(): void
    {
        $response = $this->postJson('/webhooks/ebay/account-deletion', $this->notification());

        // eBay wants a 2xx, promptly. Anything else is retried and counted
        // against compliance.
        $response->assertNoContent();
    }

    #[Test]
    public function it_never_logs_the_personal_data_of_somebody_asking_to_be_forgotten(): void
    {
        $captured = [];

        Log::listen(function ($message) use (&$captured): void {
            $captured[] = json_encode($message->context);
        });

        $this->postJson('/webhooks/ebay/account-deletion', $this->notification())->assertNoContent();

        $logged = implode(' ', $captured);

        /*
         * The whole point of the notification is that this person wants to be
         * erased.
         *
         * `laravel.log` is retained, shipped and searchable, so writing their
         * username or user id into it would create a durable record of them in
         * direct response to their erasure request — the one outcome this
         * notification exists to prevent.
         */
        $this->assertStringNotContainsString('ebay-user-42', $logged);
        $this->assertStringNotContainsString('EIAS-token-value', $logged);
        $this->assertStringNotContainsString('somebody', $logged);

        // eBay's own reference identifies nobody, and is enough to prove
        // receipt if they ever ask.
        $this->assertStringContainsString('notif-1', $logged);
    }

    #[Test]
    public function the_webhook_is_exempt_from_csrf(): void
    {
        /*
         * A server-to-server POST from outside: no session, no token to carry.
         *
         * Without the exemption this answers 419, eBay records a failure, and
         * the application is marked non compliant — which stops the production
         * keyset minting tokens. Asserted explicitly because the exemption
         * lives in bootstrap/app.php, far from this route, and nothing else
         * would notice its removal.
         */
        $this->withMiddleware()
            ->post('/webhooks/ebay/account-deletion', $this->notification())
            ->assertNoContent();
    }

    #[Test]
    public function a_malformed_payload_is_still_acknowledged(): void
    {
        /*
         * Deliberately forgiving, which is the opposite of this codebase's
         * usual instinct.
         *
         * There is nothing to validate *for* — the handler takes no action on
         * the contents — and a 4xx here would be recorded as a delivery
         * failure. eBay's schema is theirs to change, and a version bump that
         * moved a field must not be able to mark us non compliant.
         */
        $this->postJson('/webhooks/ebay/account-deletion', ['unexpected' => true])->assertNoContent();
        $this->postJson('/webhooks/ebay/account-deletion', [])->assertNoContent();
    }

    /** @return array<string, mixed> */
    private function notification(): array
    {
        return [
            'metadata' => [
                'topic' => 'MARKETPLACE_ACCOUNT_DELETION',
                'schemaVersion' => '1.0',
                'deprecated' => false,
            ],
            'notification' => [
                'notificationId' => 'notif-1',
                'eventDate' => '2026-08-31T10:00:00.000Z',
                'publishDate' => '2026-08-31T10:00:01.000Z',
                'publishAttemptCount' => 1,
                'data' => [
                    'username' => 'somebody',
                    'userId' => 'ebay-user-42',
                    'eiasToken' => 'EIAS-token-value',
                ],
            ],
        ];
    }
}
