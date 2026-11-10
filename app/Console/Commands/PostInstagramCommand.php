<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InstagramPost;
use App\Services\Social\Instagram\InstagramClient;
use App\Services\Social\Instagram\InstagramPoster;
use Illuminate\Console\Command;

/**
 * Post the day's Daily Cove and new guides to GiftCoves' Instagram account.
 * Scheduled twice a day in routes/console.php; see docs/features/instagram.md.
 *
 *     php artisan bc:post-instagram --dry-run      what would be posted, and the captions
 *     php artisan bc:post-instagram --only=daily   just today's Daily Cove
 *     php artisan bc:post-instagram --only=guides  just new guides (guides_per_run)
 */
class PostInstagramCommand extends Command
{
    protected $signature = 'bc:post-instagram
        {--only= : daily or guides; both when omitted}
        {--dry-run : Show what would be posted without posting}';

    protected $description = 'Post the Daily Cove and new guides to the GiftCoves Instagram account.';

    public function handle(InstagramPoster $poster, InstagramClient $client): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['daily', 'guides'], true)) {
            $this->components->error('--only takes "daily" or "guides".');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        if (! $dry && ! $client->configured()) {
            // Not a failure: off is a valid state, and the scheduler should not
            // report a disabled feature as a broken job. bc:check-config says why.
            $this->components->info('Instagram posting is off (INSTAGRAM_ENABLED, INSTAGRAM_ACCESS_TOKEN, INSTAGRAM_USER_ID).');

            return self::SUCCESS;
        }

        $due = collect();

        if ($only !== 'guides' && ($daily = $poster->dueDaily()) !== null) {
            $due->push($daily);
        }

        if ($only !== 'daily') {
            $due = $due->concat($poster->dueGuides());
        }

        if ($due->isEmpty()) {
            $this->components->info('Nothing to post for '.$poster->market()->value.'.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($due as $cove) {
            $label = $cove->kind->value.' '.($cove->slug ?: $cove->drop_date?->toDateString());

            if ($dry) {
                $this->components->twoColumnDetail($label, $poster->imageUrl($cove));
                $this->line($poster->caption($cove));
                $this->newLine();

                continue;
            }

            $row = $poster->post($cove);

            if ($row->status === InstagramPost::POSTED) {
                $this->components->twoColumnDetail($label, '<fg=green>posted</> '.$row->media_id);
            } else {
                $failed++;
                $this->components->twoColumnDetail($label, '<fg=red>failed</> '.$row->error);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
