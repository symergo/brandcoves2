<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The header and its menus, as regrouped on 2026-09-26: Find a gift,
 * Discover ▾ (Every day, Coves, All Coves), My Coves, one country-and-language
 * button, the account menu (docs/features/navigation.md).
 *
 * The menus are React and SSR does not run in the suite, so a missing string
 * or a dead link would never show in a rendered page here. Two checks that do
 * not depend on rendering: every destination answers, and every string the
 * menus ask for exists in all four languages. A key that does not exist is
 * printed as the key itself ("nav.every_day") on the live site.
 */
class HeaderMenuTest extends TestCase
{
    use RefreshDatabase;

    /** The files that draw the header, the menus and the phone sheets. */
    private const FILES = [
        'js/Layouts/SiteLayout.tsx',
        'js/Components/NavMenu.tsx',
        'js/Components/AccountMenu.tsx',
        'js/Components/AccountSheet.tsx',
        'js/Components/myCovesLinks.ts',
        'js/Components/MarketSwitcher.tsx',
    ];

    #[Test]
    public function every_place_the_menus_send_people_answers(): void
    {
        foreach ([
            '/be-nl/gift',
            '/be-nl/discover-cove',
            // Not the Daily Cove (/tips): with no edition published it is a 404 by design.
            '/be-nl/surprise',
            '/be-nl/gift-ideas',
            '/be-nl/guides',
            '/be-nl/coves/community',
            '/be-nl/brands',
            '/be-nl/shops',
            '/be-nl/coves',
            '/be-nl/lists',
            '/be-nl/help',
        ] as $url) {
            $status = $this->get($url)->status();

            $this->assertContains($status, [200, 301, 302], "{$url} answered {$status}.");
        }
    }

    #[Test]
    public function every_string_the_menus_use_exists_in_every_language(): void
    {
        $keys = [];

        foreach (self::FILES as $file) {
            preg_match_all("/\\bt\\('([a-z_]+\\.[a-z_.]+)'/", (string) file_get_contents(resource_path($file)), $m);
            $keys = [...$keys, ...$m[1]];
        }

        $keys = array_values(array_unique($keys));

        // The new entries are among them, so this is not passing on an empty scan.
        foreach (['nav.every_day', 'nav.brands_shops', 'nav.all_coves', 'nav.people', 'nav.help', 'nav.gift_ideas', 'nav.market_button'] as $new) {
            $this->assertContains($new, $keys, "{$new} is no longer used by the header.");
        }

        foreach (['en', 'nl', 'fr', 'es'] as $language) {
            foreach ($keys as $key) {
                $this->assertTrue(
                    trans()->hasForLocale("site.{$key}", $language),
                    "site.{$key} is missing in {$language}.",
                );
            }
        }
    }

    #[Test]
    public function the_account_menus_link_my_people_where_friends_was(): void
    {
        $links = (string) file_get_contents(resource_path('js/Components/myCovesLinks.ts'));

        $this->assertStringContainsString('${base}/people`', $links);
        $this->assertStringNotContainsString('${base}/friends`', $links);
    }
}
