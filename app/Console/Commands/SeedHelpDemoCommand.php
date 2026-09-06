<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\LoginToken;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A throwaway account with a plausible list, for the help-page screenshots.
 *
 * ## Why an account has to be invented
 *
 * The screenshots on `/lists-help` show a signed-in view: the save panel with
 * lists in it, and the lists page itself. Both are meaningless empty, and the
 * only accounts on a development machine are the developer's own — carrying
 * real gift lists with real notes on them, restored from production and scrubbed
 * of everything except the fact that these are somebody's actual presents.
 * Photographing those and committing the images would publish them.
 *
 * So this makes a list nobody minds being seen: a fictional person's birthday,
 * filled with products drawn from the local catalogue.
 *
 * Local only. It refuses in production, where it would put a fake account in
 * front of real people and in the numbers.
 *
 * Run it, then `node scripts/help-screenshots.mjs`.
 */
class SeedHelpDemoCommand extends Command
{
    public const EMAIL = 'help-screenshots@giftcoves.test';

    protected $signature = 'bc:seed-help-demo
        {--market=be-nl : The market to fill the list from.}
        {--fresh : Clear the demo account\'s existing lists first.}';

    protected $description = 'Create the demo account the help-page screenshots are taken from';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Not in production. This creates a fake account and a fake list.');

            return self::FAILURE;
        }

        $market = Market::tryFrom((string) $this->option('market'));

        if ($market === null) {
            $this->error('Unknown market. One of: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Demo', 'email_verified_at' => now()],
        );

        /*
         * Two lists, because one list photographs a special case.
         *
         * The panel that opens from a save button is a *chooser*, and a chooser
         * with a single option does not look like one — a screenshot of it
         * would teach the wrong thing about the step it illustrates.
         */
        /*
         * Titles written here rather than in `lang/`, on purpose. These are
         * props for a photograph; putting them in the shipped copy files would
         * hand every translator two strings no visitor will ever read.
         */
        $titles = match ($market->language()) {
            'nl' => ['Mijn verlanglijstje', 'Sinterklaas'],
            'fr' => ['Ma liste de souhaits', 'Anniversaire de Lea'],
            'es' => ['Mi lista de deseos', 'Cumpleanos de Lea'],
            default => ['My wish list', "Lea's birthday"],
        };

        /*
         * Clearing first is what keeps a screenshot in one language.
         *
         * The save panel lists every list the account has, whatever market it
         * was made in — so seeding three markets in turn and photographing the
         * third produced a panel reading "Mijn verlanglijstje / My wish list /
         * Anniversaire de Lea". Three languages in the picture illustrating one
         * step.
         */
        if ($this->option('fresh')) {
            Wishlist::query()->where('owner_user_id', $user->id)->delete();
        }

        /*
         * A user has at most one default list, and the uniqueness is global
         * rather than per market — `wishlists_default_user_idx`. Seeding a
         * second market would otherwise collide with the first.
         */
        $hasDefault = Wishlist::query()
            ->where('owner_user_id', $user->id)
            ->where('is_default', true)
            ->exists();

        $lists = collect($titles)->map(fn (string $title): array => ['title' => $title, 'kind' => 'mine'])->map(fn (array $spec, int $index) => Wishlist::firstOrCreate(
            [
                'owner_user_id' => $user->id,
                'market' => $market->value,
                'title' => $spec['title'],
            ],
            [
                'id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
                'kind' => $spec['kind'],
                'visibility' => 'private',
                'is_default' => $index === 0 && ! $hasDefault,
            ],
        ));

        $groups = ProductGroup::query()
            ->where('market', $market->value)
            ->whereNotNull('image_url')
            ->whereNotNull('min_price')
            ->inRandomOrder()
            ->limit(4)
            ->get();

        if ($groups->isEmpty()) {
            $this->warn("No products in {$market->value}, so the list will be empty. Run bc:ingest first.");
        }

        $list = $lists->first();

        foreach ($groups as $group) {
            WishlistItem::firstOrCreate(
                ['wishlist_id' => $list->id, 'group_id' => $group->id],
                [
                    // Snapshots, as a real save writes them: the list has to
                    // survive the product going out of stock.
                    'snapshot_title' => $group->title,
                    'snapshot_image_url' => $group->image_url,
                    'snapshot_price' => $group->min_price,
                    'snapshot_url' => "/{$market->value}/p/{$group->id}/{$group->slug}",
                ],
            );
        }

        $this->info(sprintf(
            'Demo account %s ready: %d list(s), %d item(s) in "%s".',
            self::EMAIL,
            $lists->count(),
            $groups->count(),
            $list->title,
        ));

        /*
         * A sign-in link, printed rather than emailed.
         *
         * The screenshot script needs a signed-in browser and sign-in here is a
         * dialog rather than a page — opened from a menu that has to be
         * expanded first, in whatever language the market speaks. Driving that
         * three times to photograph something else is three ways for the script
         * to break for reasons having nothing to do with the pictures.
         *
         * Printing the link is safe *because* this command is already refused
         * in production: it is a live key to an account that exists nowhere
         * else. It expires in fifteen minutes and is single-use, like every
         * other one.
         */
        $issued = LoginToken::issue(self::EMAIL, name: 'Demo');

        $this->newLine();
        $this->line('Sign-in link (15 minutes, single use):');
        $this->line(url("/{$market->value}/auth/magic/{$issued['token']}"));
        $this->newLine();
        $this->line('Now run: node scripts/help-screenshots.mjs');

        return self::SUCCESS;
    }
}
