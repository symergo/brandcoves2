<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Models\GiftLanding;
use App\Services\Editorial\HouseStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Written copy for the gift landing pages (2026-11-24, owner on advice: "Ok I follow your
 * suggestion"). docs/features/gift-landing-pages.md.
 *
 *   GET  /gift-landings?market=      every landing page of the market: path, who, interest,
 *                                    product count, and whether it has written copy
 *   POST /gift-landings/copy         {market, pages: [{path, intro, metaDescription}]}
 *
 * The write is a publish: the words reach readers on the next request, and a page with
 * written copy enters the sitemap. All or nothing: an unknown path refuses the batch, so a
 * typo never leaves half the pages written. `intro: null` returns a page to the template.
 */
class GiftLandingCopyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['market' => ['required', Rule::in(Market::values())]]);

        $pages = GiftLanding::query()
            ->where('market', $data['market'])
            ->orderBy('recipient')
            ->orderBy('interest')
            ->get(['path', 'recipient', 'interest', 'product_count', 'intro', 'meta_description']);

        return response()->json([
            'count' => $pages->count(),
            'data' => $pages->map(fn (GiftLanding $page) => [
                'path' => $page->path,
                'recipient' => $page->recipient,
                'interest' => $page->interest,
                'productCount' => $page->product_count,
                'intro' => $page->intro,
                'metaDescription' => $page->meta_description,
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'pages' => ['required', 'array', 'min:1', 'max:100'],
            'pages.*.path' => ['required', 'string', 'max:200'],
            // A few short paragraphs, separated by blank lines.
            'pages.*.intro' => ['present', 'nullable', 'string', 'max:3000'],
            'pages.*.metaDescription' => ['nullable', 'string', 'max:160'],
        ]);

        $paths = array_column($data['pages'], 'path');
        $known = GiftLanding::query()->where('market', $data['market'])->whereIn('path', $paths)->pluck('id', 'path');
        $unknown = array_values(array_diff($paths, $known->keys()->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'pages' => 'No landing page in '.$data['market'].' at: '.implode(', ', array_slice($unknown, 0, 10)),
            ]);
        }

        foreach ($data['pages'] as $page) {
            GiftLanding::query()->whereKey($known[$page['path']])->update([
                'intro' => HouseStyle::prose($page['intro'] ?? null),
                'meta_description' => HouseStyle::plain($page['metaDescription'] ?? null),
            ]);
        }

        return response()->json(['written' => count($data['pages'])]);
    }
}
