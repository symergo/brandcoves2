<?php

declare(strict_types=1);

namespace App\Services\Discover;

use App\Models\ModeProfileRecord;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Where mode profiles come from.
 *
 * Two layers, same shape as the gift angle map and for the same reason:
 *
 * 1. **`config/discovery.php`** — the declared profiles, in the repo, reviewed
 *    like code. The site works on a fresh database with an empty table.
 * 2. **`mode_profiles` rows** — overrides, editable in admin without a
 *    redeploy. Tuning α from 0.9 to 0.8 after looking at a week of reactions
 *    should not require a deployment.
 *
 * Cached for a minute: this is read on every discovery request and changes
 * roughly never, but a minute is short enough that someone tuning a weight in
 * admin sees it without wondering whether they broke something.
 */
class ModeRegistry
{
    private const CACHE_KEY = 'discovery.mode_profiles';

    /** @var array<string, ModeProfile>|null */
    private ?array $profiles = null;

    /** @return array<string, ModeProfile> */
    public function all(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $declared = (array) config('discovery.modes', []);

        $overrides = Cache::remember(self::CACHE_KEY, 60, function (): array {
            try {
                return ModeProfileRecord::query()
                    ->get()
                    ->keyBy('key')
                    ->map(fn (ModeProfileRecord $record) => $record->toProfileArray())
                    ->all();
            } catch (Throwable) {
                // The table may not exist yet during an early migration, and a
                // discovery surface that 500s because an override table is
                // missing is worse than one running on its declared defaults.
                return [];
            }
        });

        $profiles = [];

        foreach ($declared as $key => $row) {
            $merged = array_replace_recursive($row, $overrides[$key] ?? []);
            $profile = ModeProfile::fromArray($key, $merged);

            if ($profile->enabled) {
                $profiles[$key] = $profile;
            }
        }

        // Ordered along the intent axis, so the switcher can render the stops
        // in order without knowing anything about them.
        uasort($profiles, fn (ModeProfile $a, ModeProfile $b) => $a->position <=> $b->position);

        return $this->profiles = $profiles;
    }

    public function get(string $key): ModeProfile
    {
        return $this->all()[$key] ?? throw new NotFoundHttpException("Unknown discovery mode [{$key}].");
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * The blended profile at a point on the intent axis.
     *
     * This is the mode switcher. A position between two stops interpolates
     * between them rather than snapping, so the result surface visibly
     * reorganises as the dial moves — one dial, not nine screens.
     */
    public function atPosition(float $position): ModeProfile
    {
        $position = max(0.0, min(1.0, $position));
        $profiles = array_values($this->all());

        if ($profiles === []) {
            throw new NotFoundHttpException('No discovery modes are enabled.');
        }

        if (count($profiles) === 1) {
            return $profiles[0];
        }

        $lower = $profiles[0];
        $upper = $profiles[count($profiles) - 1];

        foreach ($profiles as $index => $profile) {
            if ($profile->position <= $position) {
                $lower = $profile;
                $upper = $profiles[$index + 1] ?? $profile;
            }
        }

        $span = $upper->position - $lower->position;

        // Two stops at the same position, or the dial sitting exactly on one.
        if ($span <= 0.0) {
            return $lower;
        }

        return $lower->blend($upper, ($position - $lower->position) / $span);
    }

    /** The stops the switcher renders, in axis order. */
    public function stops(): array
    {
        return array_values(array_map(
            fn (ModeProfile $p) => ['key' => $p->key, 'position' => $p->position, 'layout' => $p->layout],
            $this->all(),
        ));
    }

    public function forget(): void
    {
        $this->profiles = null;
        Cache::forget(self::CACHE_KEY);
    }
}
