<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Interest;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Services\Gift\InterestCandidates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which interests people type that the vocabulary does not have.
 *
 * Read-only, and the answer is a list to act on in code rather than a
 * write: see {@see InterestCandidates}.
 */
class InterestCandidatesController extends Controller
{
    public function __invoke(Request $request, InterestCandidates $candidates): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $market = Market::from($data['market']);
        $rows = $candidates->top($market, (int) ($data['limit'] ?? 50), (int) ($data['days'] ?? 90));

        return response()->json([
            'market' => $market->value,
            'days' => (int) ($data['days'] ?? 90),
            'count' => count($rows),
            'vocabulary' => Interest::values(),
            'data' => $rows,
        ]);
    }
}
