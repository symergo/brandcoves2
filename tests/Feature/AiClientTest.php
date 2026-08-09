<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a model response is read.
 *
 * Nothing in this suite had ever faked a response body, and that is precisely
 * how the bug these tests exist for survived: the client took
 * `content[0]['text']`, which is right until a model puts a `thinking` block
 * first, and then it is null.
 *
 * The failure was silent and expensive. Every caller catches `AiUnavailable` and
 * falls back to template copy, so the site looked fine, the usage table showed
 * successful calls with real token counts and zero errors, and not one word of
 * generated editorial ever reached a page.
 */
class AiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'brandcoves.ai.enabled' => true,
            'brandcoves.ai.api_key' => 'sk-ant-test',
            'brandcoves.ai.model' => 'claude-sonnet-5',
        ]);
    }

    /** @param list<array<string, mixed>> $content */
    private function fakeResponse(array $content, int $inputTokens = 10, int $outputTokens = 20): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => $content,
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            ]),
        ]);
    }

    #[Test]
    public function a_thinking_block_before_the_text_does_not_swallow_the_answer(): void
    {
        /*
         * The regression, in the exact shape the API returned it: index 0 is a
         * thinking block with no `text` key, and the answer is at index 1.
         *
         * A short prompt produces no thinking block, so the smoke test passed
         * and the credential looked healthy while every real generation failed.
         */
        $this->fakeResponse([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'abc'],
            ['type' => 'text', 'text' => '{"ok":true}'],
        ]);

        $result = app(AiClient::class)->json(
            featureKey: 'gift_angles',
            system: 'x',
            prompt: 'y',
            schemaHint: ['ok' => 'boolean'],
        );

        $this->assertSame(['ok' => true], $result);
    }

    #[Test]
    public function a_plain_single_text_block_still_works(): void
    {
        $this->fakeResponse([['type' => 'text', 'text' => '{"ok":true}']]);

        $this->assertSame(
            ['ok' => true],
            app(AiClient::class)->json('gift_angles', 'x', 'y', ['ok' => 'boolean']),
        );
    }

    #[Test]
    public function several_text_blocks_are_joined(): void
    {
        // A long answer can arrive split. Taking only the first would truncate
        // the JSON and fail to parse, which looks identical to a bad model.
        $this->fakeResponse([
            ['type' => 'text', 'text' => '{"a":1,'],
            ['type' => 'text', 'text' => '"b":2}'],
        ]);

        $this->assertSame(
            ['a' => 1, 'b' => 2],
            app(AiClient::class)->json('gift_angles', 'x', 'y', []),
        );
    }

    #[Test]
    public function an_unknown_block_type_is_ignored_rather_than_breaking(): void
    {
        // Stability against a model adding a block type later, which is exactly
        // what happened the first time.
        $this->fakeResponse([
            ['type' => 'redacted_thinking', 'data' => 'opaque'],
            ['type' => 'text', 'text' => '{"ok":true}'],
        ]);

        $this->assertSame(
            ['ok' => true],
            app(AiClient::class)->json('gift_angles', 'x', 'y', []),
        );
    }

    #[Test]
    public function a_response_with_no_text_block_names_what_it_did_contain(): void
    {
        /*
         * The old message was "empty response", which sent me looking at the
         * model and the prompt rather than at the parser. Naming the block types
         * puts the answer in the exception.
         */
        $this->fakeResponse([['type' => 'thinking', 'thinking' => '']]);

        try {
            app(AiClient::class)->json('gift_angles', 'x', 'y', []);
            $this->fail('expected AiUnavailable');
        } catch (AiUnavailable $e) {
            $this->assertStringContainsString('no text block', $e->getMessage());
            $this->assertStringContainsString('thinking', $e->getMessage());
        }
    }

    #[Test]
    public function tokens_are_recorded_even_when_extraction_fails(): void
    {
        // They were spent. A call that cost money and produced nothing has to
        // show up in the usage table, or the cap protects nothing and the bill
        // is a surprise.
        $this->fakeResponse([['type' => 'thinking', 'thinking' => '']], inputTokens: 500, outputTokens: 2400);

        try {
            app(AiClient::class)->json('gift_angles', 'x', 'y', []);
        } catch (AiUnavailable) {
            // expected
        }

        $usage = AiUsage::query()->where('feature_key', 'gift_angles')->first();

        $this->assertSame(1, $usage->calls);
        $this->assertSame(2400, $usage->output_tokens);
    }

    #[Test]
    public function a_fenced_reply_is_still_parsed(): void
    {
        // Models occasionally fence despite being told not to. Cheaper to strip
        // than to burn a retry on the same answer.
        $this->fakeResponse([
            ['type' => 'thinking', 'thinking' => ''],
            ['type' => 'text', 'text' => "```json\n{\"ok\":true}\n```"],
        ]);

        $this->assertSame(
            ['ok' => true],
            app(AiClient::class)->json('gift_angles', 'x', 'y', []),
        );
    }

    #[Test]
    public function an_unregistered_feature_key_cannot_spend(): void
    {
        // Spend that does not appear in the usage table is spend nobody notices.
        $this->fakeResponse([['type' => 'text', 'text' => '{}']]);

        $this->expectException(AiUnavailable::class);

        app(AiClient::class)->json('not_a_registered_feature', 'x', 'y', []);
    }
}
