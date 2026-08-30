<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\Market;
use App\Services\Guides\CoveMarkup;

/**
 * An article, paragraph by paragraph, each carrying the products it names.
 *
 * The pairing is already in the copy. A `[[product:12]]` token is the writer
 * saying "this paragraph is about that thing"; reading the ids back out per
 * paragraph is what lets the card appear where the product is discussed rather
 * than in a grid three screens below the sentence about it.
 *
 * Lifted out of `EditionPresenter` when the guide page needed the same answer.
 * Two copies of this walk would have drifted within a month, and the drift
 * would be silent: a page whose cards quietly stop following the prose still
 * renders, still passes, and simply reads worse.
 *
 * ## Why this is constructed, not injected
 *
 * It carries `$used` — the ids already claimed by an earlier paragraph — and
 * that state belongs to one document, not to the container. A guide asks it for
 * two blocks of prose (the intro, then the article) and the dedupe has to span
 * both, because a product introduced up top must not get a second card halfway
 * down. So: one instance per page render, `new`ed by the caller, never a
 * singleton.
 */
final class ProseCards
{
    /**
     * Ids already shown, in the order the document reached them.
     *
     * @var array<int, true>
     */
    private array $used = [];

    /**
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     */
    public function __construct(
        private readonly CoveMarkup $markup,
        private readonly Market $market,
        private readonly array $allowed,
    ) {}

    /**
     * @return list<array{html: string, groupIds: list<int>}>
     */
    public function blocks(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        $out = [];

        foreach (preg_split('/\R{2,}/u', trim((string) $text)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }

            $out[] = [
                'html' => $this->markup->render($paragraph, $this->market, $this->allowed)['html'],
                // Claimed before the next paragraph is walked, so "first
                // mention wins" is decided in reading order.
                'groupIds' => $this->claim($paragraph),
            ];
        }

        return $out;
    }

    /**
     * The paragraph rules handed to the model, kept next to the walk above.
     *
     * These are not house style, which is why they are here and not in an
     * editable prompt template. They are a description of `claim()`: it pairs a
     * card to the FIRST paragraph naming a product, and stacks every card a
     * paragraph names underneath it. A writer who does not know that puts two
     * products in one paragraph and gets two cards under it and a bare
     * paragraph after — and the symptom is a page that reads oddly rather than
     * an error anybody sees.
     *
     * Both callers append it: `EditionBuilder` for a Cove, `GuideWriter` for an
     * article. A prompt bank edit can change the voice; it cannot drop this.
     */
    public static function promptContract(): string
    {
        return <<<'TXT'
        - Write about EVERY product listed below. Each one gets its own paragraph,
          naming it with its link token where it is discussed.
        - One product per paragraph. Its card is rendered directly underneath the
          paragraph that names it, so two products in one paragraph stacks both
          cards under it and reads as a caption for a pair.
        TXT;
    }

    /** Every id this document has paired with a paragraph so far. */
    public function shown(): array
    {
        return array_keys($this->used);
    }

    /**
     * The ids this paragraph is allowed to show, and has not shown already.
     *
     * Two filters, for two different failures. **Allowlisted**, because a token
     * naming a product that is not on this page renders as plain text (see
     * `CoveMarkup::render()`) and pairing a card to it would put a product on
     * the page that the prose could not link to. **First mention only**,
     * because copy naturally repeats a name and a second identical card reads
     * as a stutter.
     *
     * @return list<int>
     */
    private function claim(string $paragraph): array
    {
        preg_match_all('/\[\[product:(\d+)/u', $paragraph, $matches);

        $ids = [];

        foreach ($matches[1] as $raw) {
            $id = (int) $raw;

            if (isset($this->allowed['products'][$id]) && ! isset($this->used[$id])) {
                $this->used[$id] = true;
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
