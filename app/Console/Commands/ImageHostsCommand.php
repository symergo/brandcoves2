<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Which image servers the catalogue's pictures come from, and which of them
 * the image proxy covers.
 *
 * The proxy's host list (`giftcoves.image_proxy.hosts`) is short and written
 * by hand, on purpose: every host on it is one our server will fetch from. This
 * is how a person decides what to add. Read only; it changes nothing. One scan
 * of `product_groups`, which is why it is a command someone runs and not
 * something a page asks.
 */
class ImageHostsCommand extends Command
{
    protected $signature = 'bc:image-hosts
        {--market= : One market only}
        {--limit=40 : How many hosts to list}';

    protected $description = 'List the image hosts in the catalogue and whether the image proxy covers them';

    public function handle(ImageProxy $proxy): int
    {
        $rows = DB::table('product_groups')
            ->selectRaw("lower(substring(image_url from '^https?://([^/:]+)')) as host, count(*) as pictures")
            ->whereNotNull('image_url')
            ->when($this->option('market'), fn ($q, $market) => $q->where('market', $market))
            ->groupBy('host')
            ->orderByDesc('pictures')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $this->table(
            ['Host', 'Pictures', 'Proxied'],
            $rows->map(fn ($row): array => [
                $row->host ?? '(not a URL)',
                number_format((int) $row->pictures),
                match (true) {
                    $row->host === null => '-',
                    ImageProxy::isAmazon((string) $row->host) => 'never (Amazon)',
                    $proxy->proxiable('https://'.$row->host.'/x.jpg') => 'yes',
                    default => 'no',
                },
            ])->all(),
        );

        $this->line('Add a host with IMAGE_PROXY_HOSTS=host.example,other.example (comma-separated).');

        return self::SUCCESS;
    }
}
