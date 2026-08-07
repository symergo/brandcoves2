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

    /** @var list<array<string, mixed>> */
    private array $jsonLd = [];

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
        $this->jsonLd = [];

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
