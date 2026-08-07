<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * The model could not be called, for a reason the caller is expected to handle.
 *
 * Every AI feature has a non-AI fallback — `AI_ENABLED=false` is a supported
 * way to run the whole site — so this is a normal control-flow signal, not a
 * crash. Callers catch it and use their fallback.
 */
class AiUnavailable extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('AI is disabled (AI_ENABLED=false or no API key).');
    }

    public static function capped(string $featureKey): self
    {
        return new self("Daily AI cap reached for feature [{$featureKey}].");
    }

    public static function failed(string $detail): self
    {
        return new self("AI call failed: {$detail}");
    }

    /**
     * The invariant breach.
     *
     * Deliberately not catchable-and-ignorable in spirit: if this is ever
     * thrown in production it means a request handler reached the model, and
     * the fix is to move the work to a job, never to widen the check.
     */
    public static function outsideQueuedJob(): self
    {
        return new self(
            'AI may only be called from a queued job. A request handler reached the AI client — '.
            'move the work to a job. See docs/features/ai-invariant.md.'
        );
    }
}
