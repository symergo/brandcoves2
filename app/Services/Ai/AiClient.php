<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only path to a language model in this application.
 *
 * Deliberately narrow: one method, JSON in and JSON out. Everything the site
 * asks a model for — a daily-pick theme, a guide's opening paragraph, a wider
 * set of gift queries — is a structured answer, and asking for prose we then
 * parse is how you get a feature that breaks on a Tuesday.
 *
 * ## The invariant, enforced here
 *
 * AI is only ever called from a queued job. Never from a request handler. That
 * is checked at the top of {@see chat()} rather than left to review, because a
 * violation is cheap to write, invisible in testing and expensive in
 * production: a public endpoint that triggers a model call is a way for a
 * stranger to spend money in a loop.
 *
 * The check is "are we in a console process" — Horizon workers and the
 * scheduler are, HTTP requests are not. Coarse, but it fails in the safe
 * direction and it cannot be satisfied accidentally from a controller.
 *
 * See docs/features/ai-invariant.md.
 */
class AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?bool $enabled = null,
    ) {}

    public function isEnabled(): bool
    {
        $enabled = $this->enabled ?? (bool) config('giftcoves.ai.enabled');

        return $enabled && $this->key() !== null && $this->key() !== '';
    }

    /**
     * Ask for a JSON object and get one back, or throw {@see AiUnavailable}.
     *
     * @param  string  $featureKey  must exist in config('giftcoves.ai.caps')
     * @param  array<string, mixed>  $schemaHint  shape the model is told to return
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function json(
        string $featureKey,
        string $system,
        string $prompt,
        array $schemaHint,
        int $maxTokens = 1024,
    ): array {
        $raw = $this->chat(
            $featureKey,
            $system."\n\nReply with JSON only. No prose, no code fences. Shape:\n"
                .json_encode($schemaHint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $prompt,
            $maxTokens,
        );

        // Models occasionally wrap JSON in a fence despite being told not to.
        // Cheaper to strip it than to burn a retry on the same answer.
        $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw);

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw AiUnavailable::failed('response was not JSON: '.mb_substr($raw, 0, 200));
        }

        return $decoded;
    }

    /**
     * @throws AiUnavailable
     */
    public function chat(string $featureKey, string $system, string $prompt, int $maxTokens = 1024): string
    {
        /*
         * THE INVARIANT. Checked before anything else, including the enabled
         * flag, so that a controller reaching this class fails loudly in every
         * environment rather than only where a key happens to be configured.
         */
        if (! app()->runningInConsole()) {
            throw AiUnavailable::outsideQueuedJob();
        }

        if (! $this->isEnabled()) {
            throw AiUnavailable::disabled();
        }

        // Registered features only. A caller with no cap entry would spend
        // invisibly, and the admin usage table is how spend gets noticed.
        if (! array_key_exists($featureKey, (array) config('giftcoves.ai.caps'))) {
            throw AiUnavailable::failed("unregistered feature key [{$featureKey}]");
        }

        if (! AiUsage::withinCap($featureKey)) {
            throw AiUnavailable::capped($featureKey);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->key(),
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout(60)
                ->post(self::ENDPOINT, [
                    'model' => $this->model ?? config('giftcoves.ai.model'),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (Throwable $e) {
            // A failed call still consumed budget and still counts against the
            // cap — otherwise a persistently failing feature retries forever.
            AiUsage::record($featureKey, 0, 0, failed: true);

            throw AiUnavailable::failed($e->getMessage());
        }

        if ($response->failed()) {
            AiUsage::record($featureKey, 0, 0, failed: true);

            Log::warning('AI call failed', [
                'feature' => $featureKey,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw AiUnavailable::failed('HTTP '.$response->status());
        }

        $body = $response->json();

        AiUsage::record(
            $featureKey,
            (int) ($body['usage']['input_tokens'] ?? 0),
            (int) ($body['usage']['output_tokens'] ?? 0),
        );

        $text = $this->textFrom($body);

        if ($text === '') {
            throw AiUnavailable::failed(
                'no text block in response (blocks: '
                .implode(',', array_map(fn (array $b) => $b['type'] ?? '?', (array) ($body['content'] ?? [])))
                .')'
            );
        }

        return $text;
    }

    /**
     * The assistant's text, from a response that may contain other block types.
     *
     * ## Why this is not `content[0]['text']`
     *
     * It was, and it silently broke every real generation on this site.
     *
     * A response is a LIST of content blocks, and text is not guaranteed to be
     * the first. Current models emit a `thinking` block ahead of the answer once
     * a request is long enough to warrant it, so `content[0]` is
     * `{"type":"thinking"}` with no `text` key at all.
     *
     * The failure mode was the expensive kind. Short prompts produce no thinking
     * block, so a smoke test passes and the credential looks fine. Every actual
     * Cove and every daily editorial hit the other path: the call succeeded, the
     * tokens were spent and recorded, the extraction returned null, and each
     * caller quietly used its template fallback. AI had been "on" and producing
     * nothing, while the usage table showed healthy traffic and zero errors.
     *
     * So: take every text block and join them, and ignore block types that are
     * not text. That is stable against a model adding a block type later, which
     * is exactly what happened here.
     *
     * @param  array<string, mixed>  $body
     */
    private function textFrom(array $body): string
    {
        $parts = [];

        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function key(): ?string
    {
        return $this->apiKey ?? config('giftcoves.ai.api_key');
    }
}
