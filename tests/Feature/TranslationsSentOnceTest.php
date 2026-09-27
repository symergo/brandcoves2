<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The site copy travels once per language, not with every page.
 *
 * About 115 KB of JSON, which was most of every page's data and was resent on
 * every Inertia navigation. It is a once-prop now: the browser names the keys
 * it holds, and the server leaves those props out. These tests pin the three
 * cases that matter: the first document always carries it (the server-side
 * render needs it), a later visit with the same key does not, and a visit in
 * another language gets the new strings. See docs/features/speed.md.
 */
class TranslationsSentOnceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_full_page_load_carries_the_translations_and_their_once_key(): void
    {
        $page = $this->get('/be-nl/help')->assertOk()->viewData('page');

        $this->assertArrayHasKey('translations', $page['props']);
        $this->assertNotEmpty($page['props']['translations']);

        $key = $this->onceKey($page);
        $this->assertStringStartsWith('translations:nl:', $key);
        $this->assertSame('translations', $page['onceProps'][$key]['prop']);
    }

    #[Test]
    public function an_inertia_visit_holding_the_key_is_sent_no_translations(): void
    {
        $first = $this->get('/be-nl/help')->viewData('page');
        $key = $this->onceKey($first);

        $page = $this->inertiaGet('/be-nl/help', $first['version'], $key)->json();

        $this->assertArrayNotHasKey('translations', $page['props']);
        // The metadata still comes back, so the client knows to keep its copy.
        $this->assertSame('translations', $page['onceProps'][$key]['prop']);
        // Everything else is still there.
        $this->assertArrayHasKey('market', $page['props']);
    }

    #[Test]
    public function a_visit_in_another_language_gets_that_language(): void
    {
        $first = $this->get('/be-nl/help')->viewData('page');
        $dutchKey = $this->onceKey($first);

        $page = $this->inertiaGet('/be-fr/help', $first['version'], $dutchKey)->json();

        $this->assertArrayHasKey('translations', $page['props']);
        $this->assertStringStartsWith('translations:fr:', $this->onceKey($page));
        $this->assertNotSame($first['props']['translations'], $page['props']['translations']);
    }

    #[Test]
    public function a_changed_version_sends_the_translations_again(): void
    {
        $first = $this->get('/be-nl/help')->viewData('page');

        // What a browser holds after a deploy that changed the copy: the same
        // language, an older file time.
        $page = $this->inertiaGet('/be-nl/help', $first['version'], 'translations:nl:1')->json();

        $this->assertArrayHasKey('translations', $page['props']);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function onceKey(array $page): string
    {
        $keys = array_keys(array_filter(
            $page['onceProps'] ?? [],
            fn (array $meta): bool => $meta['prop'] === 'translations',
        ));

        $this->assertCount(1, $keys, 'translations should be exactly one once-prop');

        return (string) $keys[0];
    }

    private function inertiaGet(string $url, ?string $version, string $onceKeys): TestResponse
    {
        return $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Except-Once-Props' => $onceKeys,
        ])->assertOk();
    }
}
