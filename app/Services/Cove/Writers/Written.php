<?php

declare(strict_types=1);

namespace App\Services\Cove\Writers;

/**
 * What a writer produced, and where it came from.
 *
 * `source` is the field callers actually branch on, and it is load-bearing in
 * two places. `bc:refresh-cove-copy` refuses to overwrite real editorial with
 * template copy, because replacing prose someone reads with a placeholder
 * because a model was briefly unreachable is a downgrade nobody asked for. And
 * `editorial_source` on the row is how the admin table answers "why has this
 * page got no words in it" without anyone having to guess.
 *
 * Four values, all of them a different story:
 *
 *   planned   a person wrote it. The model was never called and nothing was
 *             spent — see `CovePlan::editorial`.
 *   ai        a model wrote it.
 *   template  the shipped fallback copy, because AI is off, capped or failing.
 *   none      nothing was written at all, deliberately. A Daily with no
 *             editorial is a page of finds, which is a fine page.
 */
readonly class Written
{
    /**
     * `$items` is positional: entry N describes find N. A guide writes about
     * each product; a column writes about the set and leaves this empty.
     *
     * @param  list<array{q: string, a: string}>|null  $faq
     * @param  list<array{copy: string|null, verdict: string|null}>  $items
     */
    public function __construct(
        public ?string $title = null,
        public ?string $intro = null,
        public ?string $body = null,
        public ?array $faq = null,
        public array $items = [],
        public string $source = 'none',
        /** A column's prose: two or three paragraphs about the whole edition. */
        public ?string $editorial = null,
    ) {}

    /** Prose a person wrote, which skips the model entirely. */
    public static function planned(string $editorial): self
    {
        return new self(editorial: $editorial, source: 'planned');
    }

    public static function nothing(): self
    {
        return new self;
    }

    public function isFromModel(): bool
    {
        return $this->source === 'ai';
    }
}
