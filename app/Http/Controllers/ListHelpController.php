<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How lists work: an index and one page per capability.
 *
 * ## Why a page and not a tooltip
 *
 * Saving a product is one tap on a bookmark, and the bookmark sits on a card
 * among a dozen other things to tap. People do not find it, and the ones who do
 * find it do not always realise a list has to exist first — so the question
 * arrives as "how do I use this" rather than as a question about any one
 * control, and there was nowhere to send it.
 *
 * Next to `/lists` rather than with the legal pages, for the same reason
 * `/search-help` sits next to `/search`: it documents a tool, not the company.
 *
 * ## One page became nine (2026-09-08)
 *
 * The first version was one page: three steps with screenshots. By September
 * a list could be shared with friends by name, bought from, voted on, chipped
 * in to, talked over, quizzed, and reminded about, and Secret Santa drew names
 * beside it, and none of that was explained anywhere. The owner asked for all
 * of it. One page with all of it would be a manual nobody scrolls, so
 * `/lists-help` is an index of nine topics and each topic is a short page of
 * its own. The three-step page is the first topic and keeps its screenshots.
 *
 * ## The prose lives in lang/{language}/help_lists.php, not site.php
 *
 * site.php is shipped whole to the browser with every page; nine pages of
 * prose would ride along with the product grid. These files are read here and
 * sent as props, so a topic's words reach only the visitor who opened it. A
 * test checks the four files carry the same topics with the same sections,
 * which is what catches a translation that fell behind.
 *
 * ## Links are written [words](path) and resolved here
 *
 * The owner asked for the words a person searches for to be anchors to the
 * page that answers them: "verlanglijstje" to the lists, "Geheime Vriend" to
 * the draw, "delen" to the sharing topic. The language files write them as
 * [words](path) with a market-relative path, and this controller turns the
 * path into the market's URL, so a Dutch page links to /be-nl/... and the
 * same file serves nl-nl. The one special path is "cove", which is the
 * market's own Cove segment and differs per language.
 *
 * ## The screenshots are per language, and taken by a script
 *
 * `scripts/help-screenshots.mjs`, committed alongside, drives the real
 * interface and photographs it. Instructions with pictures of last year's
 * buttons are worse than instructions with none, and a folder of images nobody
 * can regenerate becomes exactly that within two releases. Dutch, French and
 * English are captured. **Spanish falls back to the English images** — that
 * market has no catalogue, so there is no product page to photograph in it.
 */
class ListHelpController extends Controller
{
    /**
     * The topics, in reading order. The order is the order somebody meets the
     * features: save first, then what a list is, then what goes on it, then
     * everything that happens once other people are involved.
     *
     * An allowlist rather than a slug straight into the language file: the
     * segment comes from a URL.
     *
     * @var list<string>
     */
    public const TOPICS = ['saving', 'kinds', 'items', 'sharing', 'claiming', 'group', 'santa', 'friends', 'alerts'];

    /**
     * Languages there are real screenshots for.
     *
     * Anything else falls back to English rather than to nothing: a step with
     * no picture beside the two that have one reads as a page that failed to
     * load.
     */
    private const SHOT_LANGUAGES = ['nl', 'fr', 'en'];

    /** Which file a section's `shot` key names. */
    private const SHOTS = [
        'find' => '1-find.png',
        'choose' => '2-choose-list.png',
        'lists' => '3-your-lists.png',
    ];

    public function index(CurrentMarket $current): Response
    {
        /** @var array<string, string> $copy */
        $copy = __('help_lists.index');

        app(PageMeta::class)->set(
            title: $copy['seo_title'],
            description: $copy['seo_description'],
            canonical: url($current->url('lists-help')),
            // Indexable. "How do I make a wish list" is a real question with
            // real intent, and this is the page that answers it.
            robots: null,
        );

        return Inertia::render('Lists/HelpIndex', [
            'copy' => [
                'title' => $copy['title'],
                'intro' => $copy['intro'],
                'cta_search' => $copy['cta_search'],
                'cta_lists' => $copy['cta_lists'],
            ],
            'topics' => $this->topics($current),
            'urls' => $this->urls($current),
        ]);
    }

    public function topic(CurrentMarket $current, string $marketSegment, string $topic): Response
    {
        if (! in_array($topic, self::TOPICS, true)) {
            throw new NotFoundHttpException;
        }

        /** @var array<string, mixed> $copy */
        $copy = __("help_lists.topics.{$topic}");
        /** @var array<string, string> $index */
        $index = __('help_lists.index');

        app(PageMeta::class)->set(
            title: $copy['title'],
            description: $copy['seo_description'],
            canonical: url($current->url("lists-help/{$topic}")),
            robots: null,
        );

        $language = in_array($current->get()->language(), self::SHOT_LANGUAGES, true)
            ? $current->get()->language()
            : 'en';

        $sections = array_map(fn (array $section) => [
            'title' => $section['title'],
            'body' => $this->resolveLinks($section['body'], $current),
            'shot' => isset($section['shot'])
                ? [
                    'src' => "/help/lists/{$language}/".self::SHOTS[$section['shot']],
                    'alt' => $section['alt'],
                ]
                : null,
        ], $copy['sections']);

        $topics = $this->topics($current);
        $position = array_search($topic, self::TOPICS, true);

        return Inertia::render('Lists/HelpTopic', [
            'topic' => $topic,
            'title' => $copy['title'],
            'intro' => $copy['intro'] === null ? null : $this->resolveLinks($copy['intro'], $current),
            'numbered' => (bool) $copy['numbered'],
            'sections' => $sections,
            'copy' => [
                'back' => $index['back'],
                'next' => $index['next'],
                'cta_search' => $index['cta_search'],
                'cta_lists' => $index['cta_lists'],
            ],
            'index' => $current->url('lists-help'),
            'next' => $topics[$position + 1] ?? null,
            'urls' => $this->urls($current),
        ]);
    }

    /**
     * Turn every [words](path) into [words](/market/path).
     *
     * Only market-relative paths are written in the language files, so a path
     * that already starts with a slash or a scheme is left alone rather than
     * prefixed twice.
     */
    public function resolveLinks(string $text, CurrentMarket $current): string
    {
        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/u',
            function (array $m) use ($current): string {
                $path = $m[2];

                if ($path === 'cove') {
                    $path = $current->get()->coveSegment();
                }

                $url = str_starts_with($path, '/') || str_contains($path, '://')
                    ? $path
                    : $current->url($path);

                return "[{$m[1]}]({$url})";
            },
            $text,
        );
    }

    /**
     * @return list<array{key: string, url: string, title: string, blurb: string}>
     */
    private function topics(CurrentMarket $current): array
    {
        /** @var array<string, array<string, mixed>> $all */
        $all = __('help_lists.topics');

        return array_values(array_map(fn (string $key) => [
            'key' => $key,
            'url' => $current->url("lists-help/{$key}"),
            'title' => $all[$key]['title'],
            'blurb' => $all[$key]['blurb'],
        ], self::TOPICS));
    }

    /**
     * @return array{search: string, lists: string}
     */
    private function urls(CurrentMarket $current): array
    {
        return [
            'search' => $current->url('search'),
            'lists' => $current->url('lists'),
        ];
    }
}
