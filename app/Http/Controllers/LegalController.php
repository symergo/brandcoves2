<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * About, privacy and terms.
 *
 * ## Markdown on disk, not rows in a table
 *
 * These are long documents that change a few times a year, are reviewed as a
 * whole, and want a diff when they do change — which is a file, not a database
 * row. Putting them in `copy_templates` would also be a category error: that
 * table holds interchangeable variants of one sentence, and there is exactly one
 * privacy policy.
 *
 * ## Placeholders, and why an empty one is loud
 *
 * The documents interpolate the company details from config. Anything not yet
 * filled in renders as a visible marker rather than disappearing — Belgian law
 * requires an imprint to carry the enterprise number and a registered address,
 * and an address that silently vanishes is a compliance gap nobody sees. One
 * that says "[registered address — to be completed]" gets fixed.
 */
class LegalController extends Controller
{
    /**
     * The documents, and the routes that reach them.
     *
     * An allowlist rather than a slug straight into a path: `{page}` comes from
     * a URL, and `File::get(base_path("resources/legal/{$lang}/{$page}.md"))`
     * with an unchecked segment is a directory traversal.
     */
    private const PAGES = ['about', 'privacy', 'terms'];

    /**
     * Languages a document has actually been written in.
     *
     * Anything else falls back to English **and says so**. A legal page silently
     * served in the wrong language reads as an oversight; one that states it is
     * the English text pending translation is at least honest about what the
     * reader is looking at.
     */
    private const TRANSLATED = ['en', 'nl'];

    public function __invoke(CurrentMarket $current, string $marketSegment, string $page): Response
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new NotFoundHttpException;
        }

        $market = $current->get();
        $language = in_array($market->language(), self::TRANSLATED, true) ? $market->language() : 'en';
        $untranslated = $language !== $market->language();

        $document = $this->document($page, $language);

        app(PageMeta::class)->set(
            title: $document['title'],
            description: $document['summary'],
            canonical: url($current->url($page)),
            // Indexable. An about page is a trust signal a search engine looks
            // for, and a privacy policy nobody can find is a privacy policy
            // nobody believes.
            robots: null,
        );

        return Inertia::render('Legal', [
            'page' => $page,
            'title' => $document['title'],
            'summary' => $document['summary'],
            'html' => $document['html'],
            'updated' => $document['updated'],
            'untranslated' => $untranslated,
        ]);
    }

    /**
     * Read, interpolate and render one document.
     *
     * @return array{title: string, summary: string, html: string, updated: string|null}
     */
    private function document(string $page, string $language): array
    {
        $path = resource_path("legal/{$language}/{$page}.md");

        if (! File::exists($path)) {
            throw new NotFoundHttpException("No {$page} document in {$language}.");
        }

        $raw = File::get($path);

        // Front matter: title, summary and the date the text last changed. The
        // date is written by hand rather than taken from the file's mtime — a
        // typo fix is not a policy change, and "last updated" on a legal page is
        // a claim about the policy.
        $meta = [];

        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $matches)) {
            foreach (preg_split('/\R/', $matches[1]) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $meta[trim($key)] = trim($value);
                }
            }

            $raw = $matches[2];
        }

        return [
            'title' => $meta['title'] ?? Str::headline($page),
            'summary' => $meta['summary'] ?? '',
            'updated' => $meta['updated'] ?? null,
            'html' => Str::markdown($this->interpolate($raw)),
        ];
    }

    /**
     * Fill the company details in.
     *
     * A missing value becomes a visible marker. See the class docblock: the
     * whole point is that an incomplete imprint is obvious rather than absent.
     */
    private function interpolate(string $text): string
    {
        $company = (array) config('brandcoves.company');

        foreach ($company as $key => $value) {
            $text = str_replace(
                '{{'.$key.'}}',
                filled($value) ? (string) $value : "**[{$key} — to be completed]**",
                $text,
            );
        }

        return $text;
    }
}
