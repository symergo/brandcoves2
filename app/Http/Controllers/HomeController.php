<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Guide;
use App\Models\ProductGroup;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(CurrentMarket $current): Response
    {
        $market = $current->get();

        return Inertia::render('Home', [
            'stats' => [
                // Real counts, so the scaffold tells the truth about how empty
                // the catalogue currently is rather than showing placeholders.
                'products' => ProductGroup::query()->forMarket($market)->count(),
                'comparable' => ProductGroup::query()->forMarket($market)->comparable()->count(),
                'guides' => Guide::query()->forMarket($market)->published()->count(),
            ],
        ]);
    }
}
