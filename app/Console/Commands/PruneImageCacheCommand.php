<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete the image proxy's stored copies older than the keep window.
 *
 * Two jobs in one: a picture a shop replaced behind the same URL shows again
 * after at most this many days, and the disk holds only what was viewed
 * recently. A deleted copy is made again on its next view, so this never
 * breaks a page. Only `proxy/` on the media disk: `items/` beside it is
 * people's own photos.
 */
class PruneImageCacheCommand extends Command
{
    protected $signature = 'bc:prune-image-cache
        {--days= : Keep window in days; defaults to giftcoves.image_proxy.keep_days}
        {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete image proxy copies older than the keep window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('giftcoves.image_proxy.keep_days', 30));
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $disk = Storage::disk(ImageStore::DISK);
        $root = $disk->path('proxy');

        if (! is_dir($root)) {
            $this->info('No image proxy copies stored.');

            return self::SUCCESS;
        }

        $old = 0;
        $kept = 0;
        $bytes = 0;

        // The filesystem directly rather than Storage::allFiles(): that builds a
        // list of every path first, which at a few hundred thousand copies is
        // memory for nothing.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            // A temporary file left by a request that died mid-write is always old news.
            $stale = $file->getMTime() < $cutoff || (str_ends_with($file->getFilename(), '.tmp') && $file->getMTime() < time() - 3600);

            if (! $stale) {
                $kept++;

                continue;
            }

            $old++;
            $bytes += $file->getSize();

            if (! $this->option('dry-run')) {
                @unlink($file->getPathname());
            }
        }

        $verb = $this->option('dry-run') ? 'Would delete' : 'Deleted';
        $this->info(sprintf('%s %d copies (%.1f MB) older than %d days; %d kept.', $verb, $old, $bytes / 1048576, $days, $kept));

        return self::SUCCESS;
    }
}
