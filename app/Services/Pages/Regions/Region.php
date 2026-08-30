<?php

declare(strict_types=1);

namespace App\Services\Pages\Regions;

use App\Services\Pages\Placeholders\PlaceholderFunction;
use App\Services\Pages\Placeholders\PlaceholderRegistry;

/**
 * A place on a page where an editor may put prose.
 *
 * Regions are the only part of the template that stays code, and the reason is
 * narrow: only code knows where in the markup a paragraph can go, and which
 * facts that particular spot can supply. Everything inside one is data.
 *
 * A region declared here but rendered by no page is the specific failure the
 * retired `brand_intro` surface became — an admin screen offering work that
 * silently went nowhere, where an editor could rewrite copy, be told it saved,
 * and see no change on any page. `PageRegionsTest` asserts every declared region
 * is rendered.
 */
final readonly class Region
{
    public const SECTIONS = 'sections';

    public const FLOW = 'flow';

    /**
     * @param  string  $blurb  where this renders and when it is suppressed, in words for an editor
     * @param  string  $layout  self::SECTIONS (headings open columns) or self::FLOW (one column)
     * @param  bool  $requiresContent  the guardrail insists this is non-empty in every language
     * @param  list<string>  $placeholders  names resolved against PlaceholderRegistry
     * @param  list<Condition>  $conditions
     */
    public function __construct(
        public string $page,
        public string $key,
        public string $label,
        public string $blurb,
        public string $layout,
        public bool $requiresContent,
        public array $placeholders,
        public array $conditions,
    ) {}

    public function id(): string
    {
        return "{$this->page}.{$this->key}";
    }

    /** @return list<PlaceholderFunction> */
    public function functions(): array
    {
        return PlaceholderRegistry::forNames($this->placeholders);
    }

    public function offers(string $name): bool
    {
        return in_array($name, $this->placeholders, true);
    }

    /** @return list<string> */
    public function conditionKeys(): array
    {
        return array_map(fn (Condition $c) => $c->key, $this->conditions);
    }
}
