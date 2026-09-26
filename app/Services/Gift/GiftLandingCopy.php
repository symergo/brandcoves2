<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;

/**
 * The words on a gift landing page, from templates. No model writes any of it:
 * the page is served in a web request (invariant 1), and "gift ideas for dad
 * who loves cooking" is a sentence a template gets right every time.
 *
 * The heading leads with the phrase people search for (docs/strategy.md,
 * "Naming and SEO"). The listing title is the same phrase when it fits the
 * 48 characters a search result leaves before " · GiftCoves", and a shorter
 * one when it does not: measured after the words are filled in, because
 * "votre frère ou sœur qui aime les sports nautiques" and "dad who loves
 * music" are the same template (docs/features/page-titles.md).
 */
final class GiftLandingCopy
{
    /** What a listing shows before the appended " · GiftCoves". */
    public const TITLE_BUDGET = 48;

    public function __construct(
        private readonly Market $market,
        private readonly RecipientType $recipient,
        private readonly ?Interest $interest = null,
    ) {}

    /** "Gift ideas for dad who loves cooking": the page's H1. */
    public function heading(): string
    {
        return $this->interest === null
            ? $this->t('heading_recipient')
            : $this->t('heading');
    }

    /**
     * The <title>: the heading when it fits, a shorter phrase when it does
     * not, and the bare "Dad who loves cooking" as the last resort.
     */
    public function title(): string
    {
        if ($this->interest === null) {
            return $this->t('heading_recipient');
        }

        foreach (['heading', 'title_short', 'title_bare'] as $key) {
            $title = $this->t($key);

            if (mb_strlen($title) <= self::TITLE_BUDGET) {
                return $title;
            }
        }

        return $this->t('title_bare');
    }

    public function intro(): string
    {
        return $this->interest === null ? $this->t('intro_recipient') : $this->t('intro');
    }

    public function description(int $count): string
    {
        return $this->interest === null
            ? $this->t('seo_description_recipient', ['count' => $count])
            : $this->t('seo_description', ['count' => $count]);
    }

    /** "dad", "papa", "votre enfant": the recipient as the sentence names it. */
    public function recipientName(): string
    {
        return $this->part('recipients', $this->recipient->value, 'name');
    }

    /** "Also for someone who loves cooking". */
    public function sameInterest(): string
    {
        return $this->t('same_interest');
    }

    /** "More for dad". */
    public function moreFor(): string
    {
        return $this->t('more_for');
    }

    /**
     * @param  array<string, string|int>  $extra
     */
    private function t(string $key, array $extra = []): string
    {
        return (string) trans('site.gift_landing.'.$key, [
            'recipient' => $this->recipientName(),
            'who' => $this->part('recipients', $this->recipient->value, 'who'),
            'interest' => $this->interest === null ? '' : $this->part('interests', $this->interest->value, 'name'),
            ...$extra,
        ], $this->market->language());
    }

    private function part(string $kind, string $value, string $field): string
    {
        return (string) trans("site.gift_landing.{$kind}.{$value}.{$field}", [], $this->market->language());
    }
}
