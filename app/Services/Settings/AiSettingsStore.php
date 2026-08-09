<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin-editable AI settings, overlaid onto the config.
 *
 * ## Why an overlay rather than a new API
 *
 * `AiClient`, `AiUsage` and the usage table all read `config('brandcoves.ai.*')`.
 * Introducing `AiSettings::enabled()` would mean changing every one of them and
 * would leave two ways to ask the same question — with the certainty that
 * something added later asks the old one. Instead this writes the stored values
 * *into* the config at boot, so every existing caller keeps working and the env
 * variable stays exactly what it always was: the default.
 *
 * Precedence is **database over environment**, which is the only order that makes
 * the admin screen mean anything. A setting nobody has touched has no row, and
 * the env value stands.
 *
 * ## Boot-time cost
 *
 * One cached read per request. Cached for an hour rather than seconds because
 * these change by hand, and flushed on save so an editor never waits.
 *
 * The load is wrapped: during `migrate` on a fresh database the table does not
 * exist yet, and a provider that throws there makes the deployment unrecoverable
 * — the one command you need to fix it is the one that cannot run.
 */
class AiSettingsStore
{
    public const SOURCE = 'ai';

    private const CACHE_KEY = 'bc:settings:ai';

    /**
     * Settings that may be stored, mapped to their config path.
     *
     * An allowlist, not a free-form bag. Without it, a stray row could overwrite
     * any config value in the application from the database — which is a
     * privilege escalation dressed as a settings screen.
     *
     * @var array<string, string>
     */
    private const KEYS = [
        'enabled' => 'brandcoves.ai.enabled',
        'api_key' => 'brandcoves.ai.api_key',
        'model' => 'brandcoves.ai.model',
        'default_daily_cap' => 'brandcoves.ai.default_daily_cap',
        'cap_daily_picks' => 'brandcoves.ai.caps.daily_picks',
        'cap_guide_copy' => 'brandcoves.ai.caps.guide_copy',
        'cap_gift_angles' => 'brandcoves.ai.caps.gift_angles',
    ];

    /**
     * Write the stored settings over the config.
     *
     * Called from a service provider's boot, before anything reads the config.
     */
    public function apply(): void
    {
        foreach ($this->stored() as $key => $value) {
            $path = self::KEYS[$key] ?? null;

            // A row for a key that is no longer in the allowlist is ignored
            // rather than applied. It stays in the table so it is visible, and
            // it cannot reach the config.
            if ($path !== null && $value !== null) {
                config([$path => $value]);
            }
        }
    }

    /**
     * The stored settings, keyed as in KEYS.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function (): array {
            try {
                return ConnectorSetting::query()
                    ->where('source', self::SOURCE)
                    ->get()
                    ->mapWithKeys(fn (ConnectorSetting $s) => [$s->key => $s->encrypted_value])
                    ->all();
            } catch (Throwable) {
                /*
                 * No table yet — a fresh database mid-`migrate`, or a test that
                 * has not run migrations. Returning nothing means the env
                 * defaults stand, which is the correct behaviour and, more
                 * importantly, lets `migrate` finish.
                 */
                return [];
            }
        });
    }

    /**
     * Persist a set of settings.
     *
     * A null value **deletes** the row rather than storing null, so "clear this
     * and fall back to the environment" is expressible. That is the only way to
     * undo a mistake on a machine where you cannot edit the env.
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

    /**
     * Whether a key is set in the database, as opposed to the environment.
     *
     * Shown next to each field so an administrator can tell what this screen is
     * actually controlling. Without it, a field showing the env value looks
     * editable and silently is not being used.
     */
    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }

    /**
     * A fingerprint of the API key, for display.
     *
     * Never the key. Enough to answer "is the right one in there" — which is the
     * only question anyone has — without putting a credential in an HTML
     * response, a browser cache, or a screenshot.
     */
    public function apiKeyFingerprint(): ?string
    {
        $key = (string) (config('brandcoves.ai.api_key') ?? '');

        if ($key === '') {
            return null;
        }

        return str_repeat('•', 8).substr($key, -4).' ('.strlen($key).' chars)';
    }
}
