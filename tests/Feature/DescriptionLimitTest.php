<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Catalogue\DescriptionLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 1,000-character cut, in PHP (ingestion) and in SQL (bc:trim-descriptions). If the two
 * differed, every feed run would find a "changed" description and rewrite the offer.
 */
class DescriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function texts(): array
    {
        $words = trim(str_repeat('Draadloze koptelefoon met ruisonderdrukking. ', 40));

        return [
            'short' => ['Een korte beschrijving.'],
            'exactly the limit' => [str_repeat('a', 1000)],
            'one over, no spaces' => [str_repeat('b', 1001)],
            'long, words' => [$words],
            'long, accents and emoji' => [trim(str_repeat('Crème brûlée façon grand-mère 🎁 ', 60))],
            'a space right at the cut' => [str_repeat('c', 1000).' and more words after it'],
            'line breaks' => [trim(str_repeat("Regel een\r\nRegel twee\n\tingesprongen ", 50))],
            'non-breaking spaces are not cut points' => [str_repeat("woord\u{00A0}", 220)],
        ];
    }

    #[Test]
    #[DataProvider('texts')]
    public function php_and_the_database_cut_the_same(string $text): void
    {
        $php = DescriptionLimit::cut($text);
        $sql = DB::scalar('SELECT '.str_replace('description', '?::text', DescriptionLimit::SQL), array_fill(0, substr_count(DescriptionLimit::SQL, 'description'), $text));

        $this->assertSame($php, $sql);
        $this->assertLessThanOrEqual(DescriptionLimit::CHARACTERS, mb_strlen((string) $php));
        // Cut again, nothing changes: a feed sending the same text leaves the row alone
        $this->assertSame($php, DescriptionLimit::cut($php));
    }

    #[Test]
    public function a_long_description_is_cut_on_a_word_boundary(): void
    {
        $cut = (string) DescriptionLimit::cut(trim(str_repeat('woord ', 300)));

        $this->assertStringEndsWith('woord', $cut);
        $this->assertSame(995, mb_strlen($cut));
        $this->assertNull(DescriptionLimit::cut(null));
    }

    #[Test]
    public function the_command_cuts_what_is_stored_and_a_second_run_changes_nothing(): void
    {
        $long = trim(str_repeat('Lange tekst van de winkel. ', 80));
        $ids = [];
        foreach (['long-1' => $long, 'short-1' => 'Kort.'] as $external => $description) {
            $ids[$external] = DB::table('products')->insertGetId([
                'source' => 'awin', 'external_id' => $external, 'market' => 'be-nl', 'title' => 'Iets',
                'description' => $description, 'affiliate_url' => 'https://track.example/'.$external, 'price' => 1000,
                'status' => 'active', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // VACUUM cannot run inside the test's transaction
        $this->artisan('bc:trim-descriptions', ['--write' => true, '--vacuum-every' => 0, '--pause' => 0])->assertSuccessful();

        $this->assertSame(DescriptionLimit::cut($long), DB::table('products')->where('id', $ids['long-1'])->value('description'));
        $this->assertSame('Kort.', DB::table('products')->where('id', $ids['short-1'])->value('description'));

        $this->artisan('bc:trim-descriptions', ['--write' => true, '--vacuum-every' => 0, '--pause' => 0])
            ->expectsOutputToContain('Done: 0 descriptions cut')
            ->assertSuccessful();
    }
}
