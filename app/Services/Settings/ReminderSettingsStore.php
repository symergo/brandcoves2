<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When a reminder fires, and whether it also goes by email.
 *
 * The same overlay {@see AiSettingsStore} is: stored values are written *into*
 * the config at boot, so `SendOccasionReminders` keeps reading
 * `config('giftcoves.reminders.*')` and there is exactly one way to ask the
 * question. Precedence is database over the shipped default, which is the only
 * order that makes an admin screen mean anything.
 *
 * A separate store rather than more keys on the AI one because they are edited
 * on separate screens by separate reasoning, and `SOURCE` is what keeps the two
 * sets of rows apart in a table shared by every subsystem.
 *
 * ## Why the lead days are a setting at all
 *
 * They were a `const LEAD_DAYS = [14, 3]` on the job. Changing them was a
 * deploy — for a number whose right value is a judgement about how people shop,
 * which is exactly the sort of thing you want to try, look at, and change again
 * without asking a developer.
 */
class ReminderSettingsStore
{
    public const SOURCE = 'reminders';

    private const CACHE_KEY = 'bc:settings:reminders';

    /**
     * Settings that may be stored, mapped to their config path.
     *
     * An allowlist, not a free-form bag — without it a stray row could
     * overwrite any config value in the application from the database, which is
     * a privilege escalation dressed as a settings screen.
     *
     * @var array<string, string>
     */
    private const KEYS = [
        'lead_days' => 'giftcoves.reminders.lead_days',
        'email' => 'giftcoves.reminders.email',
    ];

    /**
     * How many windows an administrator may set.
     *
     * A cap rather than a caution. Every lead is a separate notification and a
     * separate email about the same date, and the failure mode of six of them
     * is not a busy inbox — it is a muted channel, silent on the day that
     * matters.
     */
    public const MAX_LEADS = 5;

    /** Write the stored settings over the config, from a provider's boot. */
    public function apply(): void
    {
        $stored = $this->stored();

        if (isset($stored['lead_days'])) {
            $days = self::parseDays((string) $stored['lead_days']);

            // An empty or unparseable row leaves the default standing rather
            // than switching reminders off by accident. "No reminders" is
            // expressible — clear the row — and it should not be reachable by
            // typing a comma.
            if ($days !== []) {
                config([self::KEYS['lead_days'] => $days]);
            }
        }

        if (isset($stored['email'])) {
            config([self::KEYS['email'] => (bool) $stored['email']]);
        }
    }

    /**
     * "30, 15, 2" from a text field into a usable list.
     *
     * Descending, unique, positive, capped. Descending because the reminders
     * fire in that order and a list that reads 2, 30, 15 on the screen invites
     * somebody to wonder whether the order means something. Unique because two
     * identical leads would write the same notification twice — the dedupe key
     * includes the lead, so it would not catch it.
     *
     * @return list<int>
     */
    public static function parseDays(string $raw): array
    {
        $days = collect(preg_split('/[^0-9]+/', $raw) ?: [])
            ->map(fn (string $part): int => (int) $part)
            ->filter(fn (int $day): bool => $day > 0 && $day <= 365)
            ->unique()
            ->sortDesc()
            ->take(self::MAX_LEADS)
            ->values();

        return $days->all();
    }

    /** The current windows, as the admin field shows them. */
    public static function format(array $days): string
    {
        return implode(', ', $days);
    }

    /**
     * The stored settings, keyed as in KEYS.
     *
     * The try wraps the cache call rather than the query inside it, for the
     * reason `AiSettingsStore` records at length: there are three ways this
     * runs without a reachable database — a Docker build, `migrate` against a
     * fresh schema, and a test that has not migrated — and in all three the
     * right answer is no overrides and a completed boot.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 3600, function (): array {
                return ConnectorSetting::query()
                    ->where('source', self::SOURCE)
                    ->get()
                    ->mapWithKeys(fn (ConnectorSetting $s) => [$s->key => $s->encrypted_value])
                    ->all();
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Persist a set of settings.
     *
     * A null value deletes the row rather than storing null, so "clear this and
     * fall back to the shipped default" is expressible.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key])) {
                continue;
            }

            if ($value === null) {
                ConnectorSetting::query()
                    ->where('source', self::SOURCE)
                    ->where('key', $key)
                    ->delete();

                continue;
            }

            ConnectorSetting::updateOrCreate(
                ['source' => self::SOURCE, 'key' => $key],
                ['encrypted_value' => $value],
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Whether a key is set here, as opposed to being the shipped default. */
    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }
}
