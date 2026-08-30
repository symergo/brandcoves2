<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\CoveKind;
use App\Models\PromptTemplate;
use App\Services\Ai\Prompts\Defaults;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * What the writer is told, and where an editor can change it.
 *
 * Every prompt used to be a heredoc, so the editorial voice was a deploy. This
 * resolves a slot to either the shipped default or an override somebody wrote in
 * the admin — and the shipped default always wins when there is nothing, or
 * nothing usable, in the database.
 *
 * ## The fallback is load-bearing
 *
 * A slot with no row, a blank row, a disabled row or an unknown name all resolve
 * to the default. `prompt_templates` can be empty, half-filled or wrong and every
 * build still produces exactly what it produced before the table existed. An
 * editor cannot break a Cove by deleting a row, which is what makes the table
 * safe to hand over — the same guarantee page templates give a region.
 *
 * ## What is NOT here
 *
 * Three things stay in code and are appended by the caller after the editable
 * system text. The first two describe how the page renders, which is not a
 * matter of house style:
 *
 * 1. `CoveMarkup::promptContract()` — the link-token contract and the article's
 *    product/brand allowlist. If an editor could delete it, every
 *    `[[product:…]]` would stop being produced and articles would silently lose
 *    their internal links.
 * 2. `ProseCards::promptContract()` — one paragraph per product, every product
 *    covered. Each card is placed under the paragraph naming it, so an edit
 *    that dropped this would empty the article of products and leave all seven
 *    stacked at the foot of the page.
 * 3. What curation adds — the order somebody chose and the note explaining each
 *    choice — which is derived from the plan in front of the builder rather
 *    than from a setting.
 *
 * ## Precedence
 *
 * Shipped default → this table → the plan's `build_instructions`. The per-plan
 * direction still goes in the *user* prompt beneath the system rules, so a plan
 * cannot overturn a house rule.
 */
class PromptBank
{
    private const CACHE_KEY = 'bc:prompts';

    /**
     * Long, because these change by hand and the cost of a stale one is an
     * editor refreshing twice. Flushed on save, so nobody actually waits.
     */
    private const CACHE_TTL = 3600;

    /**
     * Every slot that may be overridden, and what it is for.
     *
     * An allowlist rather than a free-form bag: a row for a name that is not
     * here is inert, so a stale row cannot reach a caller that no longer expects
     * it. The Cove kinds are derived rather than listed, because a sixth kind
     * should not need to be remembered in two places.
     *
     * @return array<string, string>
     */
    public static function slots(): array
    {
        $slots = [];

        foreach (CoveKind::cases() as $kind) {
            $slots['cove.'.$kind->value] = $kind->label().' — the article';
        }

        return [
            ...$slots,
            'cove.theme' => 'Daily Cove — naming the day',
        ];
    }

    /**
     * The placeholders a slot's user template may contain.
     *
     * Validated on save, and the reason that validation exists: a template is
     * assembled from data, so one that has lost `{finds}` asks the model to
     * write about nothing — and a model asked to write about nothing writes a
     * plausible article about products that are not on the page.
     *
     * @return array{allowed: list<string>, required: list<string>}
     */
    public static function placeholders(string $slot): array
    {
        /*
         * Per slot, not per "article or column".
         *
         * Each kind's brief carries different facts, and offering a placeholder
         * the writer never binds is worse than not offering it: it renders as
         * nothing, so the template looks right and quietly drops a line.
         *
         * `{occasion}` is the clearest case. A Daily has one; a persona is
         * undated and can never have one, and a template that referred to it
         * would be silently blank forever.
         */
        return match ($slot) {
            'cove.daily' => [
                'allowed' => ['language', 'title', 'occasion', 'direction', 'curated', 'finds'],
                'required' => ['language', 'finds'],
            ],
            'cove.persona' => [
                'allowed' => ['language', 'title', 'direction', 'curated', 'finds'],
                'required' => ['language', 'finds'],
            ],
            'cove.guide' => [
                'allowed' => ['language', 'topic', 'title', 'direction', 'curated', 'finds'],
                'required' => ['language', 'finds'],
            ],
            'cove.seasonal' => [
                'allowed' => ['language', 'topic', 'title', 'season', 'direction', 'curated', 'finds'],
                'required' => ['language', 'finds'],
            ],
            /*
             * The one kind with no shortlist.
             *
             * `{finds}` is not required here, and requiring it would be a rule
             * that exists only to be inert — an advice article has no products
             * by definition, and the block would render empty on every one.
             */
            'cove.advice' => [
                'allowed' => ['language', 'topic', 'title', 'direction'],
                'required' => ['language'],
            ],
            /*
             * A Shop Cove is briefed like an advice article: the subject is a
             * name and a direction, and there are no finds to describe. `topic`
             * carries the shop's name.
             */
            'cove.shop' => [
                'allowed' => ['language', 'topic', 'title', 'direction'],
                'required' => ['language'],
            ],
            'cove.theme' => [
                'allowed' => ['language', 'finds', 'recent'],
                'required' => ['language', 'finds'],
            ],
            default => ['allowed' => [], 'required' => []],
        };
    }

    /** The system half: the rules and the voice. */
    public function system(string $slot): string
    {
        return $this->override($slot)['system'] ?? Defaults::system($slot);
    }

    /**
     * What the application ships with, for a slot.
     *
     * Read by the admin screen, which offers it as the starting point for an
     * override. An editor handed an empty textarea writes a *different* prompt
     * rather than a modified one, and loses the rules that stop the model
     * inventing prices and naming products that are not on the page.
     *
     * @return array{system: string, user_template: string}
     */
    public static function shipped(string $slot): array
    {
        return [
            'system' => Defaults::system($slot),
            'user_template' => Defaults::user($slot),
        ];
    }

    /**
     * The user half, rendered from named blocks.
     *
     * `$bindings` are pre-rendered strings — the product list, the curated list,
     * the editor's direction — because the alternative is a template language
     * that can loop, and a template language in a settings screen is a program
     * nobody reviews.
     *
     * A binding that is null or empty removes its placeholder *and the blank
     * line around it*, so an edition with no curated products does not ship a
     * prompt with a hole where the shortlist would be.
     *
     * @param  array<string, string|null>  $bindings
     */
    public function user(string $slot, array $bindings): string
    {
        $template = $this->override($slot)['user_template'] ?? Defaults::user($slot);

        foreach ($bindings as $name => $value) {
            $template = str_replace('{'.$name.'}', (string) $value, $template);
        }

        // Any placeholder the caller did not bind is removed rather than left
        // as literal braces in front of the model.
        $template = preg_replace('/\{[a-z_]+\}/', '', (string) $template) ?? '';

        // Collapse the gaps an empty block leaves behind.
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $template));
    }

    /**
     * Check a template before it is stored.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $slot, ?string $template): void
    {
        if (blank($template)) {
            // Blank means "use the shipped default", which is always allowed.
            return;
        }

        ['allowed' => $allowed, 'required' => $required] = self::placeholders($slot);

        preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
        $used = array_unique($matches[1]);

        $unknown = array_diff($used, $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown placeholder: {'.implode('}, {', $unknown).'}. '.
                'Available here: {'.implode('}, {', $allowed).'}.'
            );
        }

        $missing = array_diff($required, $used);

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Missing {'.implode('}, {', $missing).'}. Without it the model is '.
                'asked to write about nothing, and it will write something.'
            );
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The stored override for a slot, if there is a usable one.
     *
     * @return array{system: ?string, user_template: ?string}
     */
    private function override(string $slot): array
    {
        $none = ['system' => null, 'user_template' => null];

        if (! array_key_exists($slot, self::slots())) {
            return $none;
        }

        $row = $this->stored()[$slot] ?? null;

        if ($row === null || ! ($row['enabled'] ?? false)) {
            return $none;
        }

        // Blank is not an override. A cleared field means "back to the shipped
        // default", not "send the model an empty system prompt".
        return [
            'system' => filled($row['system'] ?? null) ? $row['system'] : null,
            'user_template' => filled($row['user_template'] ?? null) ? $row['user_template'] : null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        /*
         * The try wraps the cache call, not just the query.
         *
         * Same reason as `AiSettingsStore::stored()`: `package:discover` boots
         * the application during the Docker build, where the cache store falls
         * back to a database driver pointing at a file that does not exist — and
         * the exception comes from the cache lookup, several frames before the
         * query. No overrides and a completed boot is the right answer in all
         * three cases that reach here without a database.
         */
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => PromptTemplate::query()
                ->get(['slot', 'system', 'user_template', 'enabled'])
                ->keyBy('slot')
                ->map(fn (PromptTemplate $row) => $row->only(['system', 'user_template', 'enabled']))
                ->all());
        } catch (Throwable) {
            return [];
        }
    }
}
