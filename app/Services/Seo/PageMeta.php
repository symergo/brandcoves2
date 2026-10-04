<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * Per-page SEO metadata, rendered into the document head by Blade.
 *
 * Kept server-side rather than set from React: `<title>` and `<meta>` written by
 * client JS are invisible to social card scrapers and to any crawler that does
 * not execute scripts.
 *
 * REQUEST-SCOPED, deliberately. An earlier version used static properties and
 * leaked: JSON-LD accumulated across requests, so a page ended up carrying the
 * structured data of every page rendered before it in the same process. Under
 * PHP-FPM that is invisible (one process per request) but under FrankenPHP's
 * persistent workers it means one visitor's product page can advertise another
 * product's price. Bound as a scoped singleton so the container clears it
 * between requests.
 */
class PageMeta
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private ?string $canonical = null;

    private ?string $robots = null;

    /** What a shared link says, when it should differ from the search listing. See social(). */
    private ?string $socialTitle = null;

    private ?string $socialDescription = null;

    /** @var list<array<string, mixed>> */
    private array $jsonLd = [];

    /**
     * hreflang alternates the page already knows, or null to resolve them.
     *
     * The shell resolves alternates from the path on every full page load,
     * and for a product that meant `Alternates::product()` re-fetching the
     * group the controller had just loaded plus the sibling query — two extra
     * queries on the most-crawled page type. A controller holding the row
     * hands its alternates over here instead; a null leaves the shell to ask.
     *
     * @var array<string, string>|null
     */
    private ?array $alternates = null;

    /** @param array<string, string> $alternates hreflang => absolute URL */
    public function setAlternates(array $alternates): self
    {
        $this->alternates = $alternates;

        return $this;
    }

    /**
     * @param  string|null  $robots  e.g. 'noindex, follow' for thin or filtered pages
     */
    public function set(
        string $title,
        ?string $description = null,
        ?string $image = null,
        ?string $canonical = null,
        ?string $robots = null,
    ): self {
        $this->title = $title;
        // Truncated on a word boundary: a description cut mid-word looks broken
        // in a search listing, and Google truncates around 155 characters anyway.
        $this->description = $description === null ? null : $this->truncate($description, 155);
        $this->image = $image;
        $this->canonical = $canonical;
        $this->robots = $robots;
        $this->socialTitle = null;
        $this->socialDescription = null;

        return $this;
    }

    /**
     * A title and description for the shared-link card only (og:, twitter:).
     *
     * For the homepage (2026-10-04). Its `<title>` and meta description are
     * written for a search listing and carry the words people search for
     * ("verlanglijsten, cadeau-ideeën"); a link pasted into a chat is better
     * served by the front page's own headline. Call after set(), which clears
     * these. A page that never calls it shares its search title, as before.
     */
    public function social(?string $title, ?string $description = null): self
    {
        $this->socialTitle = $title;
        $this->socialDescription = $description === null ? null : $this->truncate($description, 200);

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function addJsonLd(array $data): self
    {
        $this->jsonLd[] = $data;

        return $this;
    }

    /**
     * Clear everything, called once per request by SetMarket.
     *
     * Belt and braces on top of the scoped binding. Container scoping only
     * resets where something calls forgetScopedInstances() — Octane does, the
     * test client does not, and a future runtime might not either. An explicit
     * reset makes "one page's metadata never reaches another page" true in
     * every environment rather than in most of them.
     */
    public function reset(): self
    {
        $this->title = null;
        $this->description = null;
        $this->image = null;
        $this->canonical = null;
        $this->robots = null;
        $this->socialTitle = null;
        $this->socialDescription = null;
        $this->jsonLd = [];
        $this->alternates = null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'canonical' => $this->canonical,
            'robots' => $this->robots,
            'social_title' => $this->socialTitle ?? $this->title,
            'social_description' => $this->socialDescription ?? $this->description,
            'alternates' => $this->alternates,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function jsonLd(): array
    {
        return $this->jsonLd;
    }

    private function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut, ' ,.;:-').'…';
    }
}
