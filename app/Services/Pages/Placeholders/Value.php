<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

/**
 * What a placeholder function returns.
 *
 * ## Why this is not a string
 *
 * `:brand_links` has to produce anchors, and the obvious way to allow that is to
 * let an editor type HTML into the body. This codebase has already refused that
 * once, in as many words — *"what you never do with model output is hand it to
 * something that interprets markup"* — and the reasoning does not weaken because
 * the author is a colleague rather than a model. An admin screen that renders
 * arbitrary markup is one stored `<script>` away from being the site's worst
 * vulnerability, and it would be reached through the one form we tell people is
 * safe to hand over.
 *
 * So a value carries **data**, the renderer carries the markup, and there is no
 * path from a textarea to an element. A paragraph resolves to a list of these
 * rather than to a string, and React maps each to a component.
 *
 * ## The four shapes, and the cost of a fifth
 *
 * `text` covers every scalar. `links` is an inline list of anchors. `chips` is a
 * block-level pill row. `nothing` is the honest empty, and it hides whatever
 * named it.
 *
 * A new function returning one of these is PHP only. A new function needing a
 * *fifth* shape is PHP plus one branch in `Parts.tsx`. That is the whole
 * boundary, and it is worth stating plainly rather than discovering.
 */
final readonly class Value
{
    public const TEXT = 'text';

    public const LINKS = 'links';

    public const CHIPS = 'chips';

    public const NOTHING = 'nothing';

    /**
     * @param  list<array{label: string, url: string}>  $items
     */
    private function __construct(
        public string $type,
        public string $text = '',
        public array $items = [],
    ) {}

    public static function text(string|int|float|null $value): self
    {
        return new self(self::TEXT, text: $value === null ? '' : (string) $value);
    }

    /** @param list<array{label: string, url: string}> $items */
    public static function links(array $items): self
    {
        return $items === [] ? self::nothing() : new self(self::LINKS, items: $items);
    }

    /** @param list<array{label: string, url: string}> $items */
    public static function chips(array $items): self
    {
        return $items === [] ? self::nothing() : new self(self::CHIPS, items: $items);
    }

    public static function nothing(): self
    {
        return new self(self::NOTHING);
    }

    /**
     * The value the `Absence` rule is applied to.
     *
     * A list collapses to its own emptiness, so an empty `:brand_links`
     * disqualifies a phrasing by exactly the mechanism a missing `:percent`
     * does. One rule, one place, no per-type special case at the call site.
     */
    public function raw(): mixed
    {
        return match ($this->type) {
            self::TEXT => $this->text,
            self::NOTHING => null,
            default => $this->items,
        };
    }

    /** Can this sit inside a sentence? */
    public function isInline(): bool
    {
        return $this->type === self::TEXT || $this->type === self::LINKS;
    }

    /** @return array{t: string, v?: string, items?: list<array{label: string, url: string}>} */
    public function toPart(): array
    {
        return $this->type === self::TEXT
            ? ['t' => self::TEXT, 'v' => $this->text]
            : ['t' => $this->type, 'items' => $this->items];
    }
}
