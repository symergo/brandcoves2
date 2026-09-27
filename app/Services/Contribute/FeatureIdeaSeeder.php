<?php

declare(strict_types=1);

namespace App\Services\Contribute;

use App\Enums\FeatureStatus;
use App\Enums\ModerationStatus;
use App\Models\FeatureIdea;

/**
 * Put the shipped ideas (resources/content/feature-ideas.php) on the board.
 *
 * The same contract as the advice and shop Cove seeders: idempotent, matched
 * on a stable key (`seed_key`), and **it never overwrites a person**. A row
 * whose `source` is still `seed` is refreshed from the file; one the owner
 * edited in the admin (`source` became `owner`) is reported as kept and left
 * exactly as it is. `$replace` overrides that, and the command asks first.
 *
 * Shared by `bc:seed-feature-ideas` and the migration that seeds a deployed
 * database, so the two cannot disagree about what "seeding" means.
 */
final class FeatureIdeaSeeder
{
    /**
     * @return array{written: list<string>, kept: list<string>}
     */
    public function run(bool $dryRun = false, bool $replace = false, ?string $path = null): array
    {
        /** @var array<string, array<string, mixed>> $content */
        $content = require $path ?? resource_path('content/feature-ideas.php');

        $written = [];
        $kept = [];

        foreach ($content as $key => $idea) {
            $existing = FeatureIdea::query()->where('seed_key', $key)->first();

            if ($existing !== null && $existing->source !== FeatureIdea::SOURCE_SEED && ! $replace) {
                $kept[] = $key;

                continue;
            }

            $written[] = $key;

            if ($dryRun) {
                continue;
            }

            $titles = [];
            $bodies = [];

            foreach (FeatureIdea::LANGUAGES as $language) {
                if (isset($idea[$language]['title'])) {
                    $titles[$language] = (string) $idea[$language]['title'];
                    $bodies[$language] = (string) ($idea[$language]['body'] ?? '');
                }
            }

            FeatureIdea::query()->updateOrCreate(
                ['seed_key' => $key],
                [
                    'title' => $titles,
                    'body' => $bodies,
                    // Written in Dutch first, as the owner wrote the list.
                    'language' => 'nl',
                    'status' => FeatureStatus::from((string) $idea['status'])->value,
                    'sort' => (int) ($idea['sort'] ?? 0),
                    'moderation' => ModerationStatus::Published->value,
                    'source' => FeatureIdea::SOURCE_SEED,
                    // Stamped once, like `published_at` on seeded Coves.
                    'decided_at' => $existing?->decided_at ?? now(),
                ],
            );
        }

        return ['written' => $written, 'kept' => $kept];
    }
}
