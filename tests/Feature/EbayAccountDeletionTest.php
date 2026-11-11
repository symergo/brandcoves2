<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
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

    /**
     * The hash is computed here from the specification's own recipe rather
     * than from the controller's, so the test disagrees with the code if the
     * code drifts.
     *
     * Order is part of the specification: code, then token, then endpoint.
     * Any other order produces a perfectly valid-looking hex string that eBay
     * rejects, and the portal's failure message names none of the three inputs.
     *
     * @return array<string, array{?string, string, string, string}>
     */
    public static function challenges(): array
    {
        return [
            'configured endpoint' => [self::ENDPOINT, '/webhooks/ebay/account-deletion', 'abc123challenge', self::ENDPOINT],
            // Whoever pastes the URL should not be able to break the hash with
            // a keystroke that means nothing.
            'trailing slash on the configured endpoint' => [self::ENDPOINT.'/', '/webhooks/ebay/account-deletion', 'xyz', self::ENDPOINT],
            // Keeps local and staging working without ceremony. Production sets
            // the value; everywhere else the URL eBay called is the URL to hash.
            'no endpoint configured: the request url' => [null, 'http://localhost/webhooks/ebay/account-deletion', 'xyz', 'http://localhost/webhooks/ebay/account-deletion'],
        ];
    }

    #[Test]
    #[DataProvider('challenges')]
    public function it_answers_the_challenge_with_the_hash_ebay_expects(?string $configuredEndpoint, string $requestUrl, string $code, string $hashedEndpoint): void
    {
        config(['giftcoves.connectors.ebay.deletion.endpoint' => $configuredEndpoint]);

        $this->getJson($requestUrl.'?challenge_code='.$code)
            ->assertOk()
            ->assertExactJson([
                'challengeResponse' => hash('sha256', $code.self::TOKEN.$hashedEndpoint),
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

    /** @return array<string, array{?string}> */
    public static function unusableTokens(): array
    {
        return [
            'unset' => [null],
            // One short of eBay's minimum, and otherwise perfectly usable. It
            // hashes fine locally and fails in the portal, where the message
            // says nothing about length, so the check belongs on our side.
            '31 characters' => [str_repeat('a', 31)],
            'a character outside eBay\'s alphabet' => [str_repeat('a', 40).'!'],
        ];
    }

    /**
     * Answering incorrectly is worse than failing.
     *
     * A wrong hash records a validation failure against an endpoint that is
     * otherwise fine, and the fix then looks like a code problem rather than a
     * missing environment variable.
     */
    #[Test]
    #[DataProvider('unusableTokens')]
    public function a_token_ebay_would_not_accept_is_refused(?string $token): void
    {
        config(['giftcoves.connectors.ebay.deletion.verification_token' => $token]);

        $this->getJson('/webhooks/ebay/account-deletion?challenge_code=xyz')->assertStatus(503);
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

    #[Test]
    public function the_webhook_is_exempt_from_the_form_security_check(): void
    {
        /*
         * Asserted on the exemption list rather than by posting without a
         * token, because Laravel skips that check for the whole suite
         * (`PreventRequestForgery` returns early under `runningUnitTests()`).
         * A test that posts and gets a 204 therefore passes whether or not
         * this path is exempt, which is how the previous one proved nothing.
         *
         * eBay posts server to server, with no session and no token to carry,
         * and a 419 here marks the application non compliant in their portal —
         * which stops the production keyset minting tokens at all.
         */
        $never = new ReflectionProperty(PreventRequestForgery::class, 'neverVerify');

        $this->assertContains('webhooks/ebay/account-deletion', $never->getValue());
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
