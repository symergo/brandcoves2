<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The shops' affiliate ids and API credentials, editable in the admin.
 *
 * The same overlay as {@see AiSettingsStore}, for the same reasons: every
 * caller keeps reading the config it always read — `Market::bolPartnerSiteId()`,
 * `AmazonSearchLink::for()`, `BolConnector` — and the stored values are written
 * over it at boot. The values set in Coolify stay the defaults; a setting
 * nobody has touched here has no row and Coolify's value stands. Database over
 * environment, the only order that makes the screen mean anything.
 *
 * ## What is here, and what deliberately is not
 *
 * bol's two partner site ids and its API client id and secret, and the Amazon
 * Associates tag per storefront. Those are everything the code actually reads.
 * The Amazon API keys in the config are not here because no Amazon API client
 * exists: a field for them would save a value that changes nothing, which is
 * worse than no field. Add them with the client.
 *
 * ## Why a wrong id matters more than it looks
 *
 * A partner id or an Associates tag goes into every outbound link. A wrong one
 * breaks nothing anybody can see — the visitor reaches the shop, the sale
 * happens — and the commission goes to nobody. That is why the ids are shown in
 * full on the page and validated on save, while the API credentials, which are
 * secrets, never leave the server.
 */
class AffiliateSettingsStore
{
    public const SOURCE = 'affiliate';

    private const CACHE_KEY = 'bc:settings:affiliate';

    /**
     * Settings that may be stored, each mapped to the config paths it writes.
     *
     * An allowlist, as in AiSettingsStore: a stray row must not be able to
     * overwrite any config value in the application. A list of paths rather
     * than one, because the Belgian Amazon tag serves two markets off one
     * storefront and must not be settable for one of them alone.
     *
     * @var array<string, list<string>>
     */
    private const KEYS = [
        'bol_partner_site_id_be' => ['giftcoves.connectors.bol.partner_site_id.BE'],
        'bol_partner_site_id_nl' => ['giftcoves.connectors.bol.partner_site_id.NL'],
        'bol_client_id' => ['giftcoves.connectors.bol.client_id'],
        'bol_client_secret' => ['giftcoves.connectors.bol.client_secret'],
        'amazon_tag_nl' => ['giftcoves.amazon_search.markets.nl-nl.tag'],
        'amazon_tag_be' => [
            'giftcoves.amazon_search.markets.be-nl.tag',
            'giftcoves.amazon_search.markets.be-fr.tag',
        ],
    ];

    /** Never rendered back; shown only as a fingerprint. */
    public const SECRETS = ['bol_client_id', 'bol_client_secret'];

    /** A change to one of these means bol's cached login is for the wrong account. */
    public const BOL_CREDENTIALS = ['bol_client_id', 'bol_client_secret'];

    /**
     * Write the stored settings over the config.
     *
     * Called from a service provider's boot, before anything reads the config.
     */
    public function apply(): void
    {
        foreach ($this->stored() as $key => $value) {
            // A row for a key no longer in the allowlist is ignored, not applied.
            if ($value === null || ! isset(self::KEYS[$key])) {
                continue;
            }

            foreach (self::KEYS[$key] as $path) {
                config([$path => $value]);
            }
        }
    }

    /**
     * The stored settings, keyed as in KEYS.
     *
     * The try wraps the cache call as well as the query, for the reason spelled
     * out in AiSettingsStore::stored(): a build, a migrate on a fresh schema and
     * an unmigrated test all boot with no reachable database, and in all three
     * the right answer is no overrides and a boot that completes.
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
     * Persist a set of settings, and say which ones actually changed.
     *
     * A null or empty value **deletes** the row, so the value in Coolify stands
     * again: that is the undo for a mistake typed here. Keys outside the
     * allowlist are ignored. The return value is what the page uses to decide
     * whether bol's login has to be redone and the workers restarted, so a save
     * that changed nothing restarts nothing.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> the keys whose stored value is now different
     */
    public function put(array $values): array
    {
        $before = $this->stored();
        $changed = [];

        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key])) {
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                if (array_key_exists($key, $before)) {
                    ConnectorSetting::query()
                        ->where('source', self::SOURCE)
                        ->where('key', $key)
                        ->delete();
                    $changed[] = $key;
                }

                continue;
            }

            if (($before[$key] ?? null) !== $value) {
                ConnectorSetting::updateOrCreate(
                    ['source' => self::SOURCE, 'key' => $key],
                    ['encrypted_value' => $value],
                );
                $changed[] = $key;
            }
        }

        $this->flush();

        return $changed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Whether a key is set here, as opposed to coming from Coolify. */
    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->stored());
    }

    /** The stored override, or null when Coolify's value stands. */
    public function override(string $key): ?string
    {
        $value = $this->stored()[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** The value in effect right now, wherever it came from. */
    public function current(string $key): ?string
    {
        $path = self::KEYS[$key][0] ?? null;
        $value = $path === null ? null : config($path);

        return blank($value) ? null : (string) $value;
    }

    /**
     * A secret, as something safe to show.
     *
     * The last four characters and the length: enough to answer "is the right
     * one in there" without putting a credential in an HTML response, a
     * browser's form cache or a screenshot.
     */
    public function fingerprint(string $key): ?string
    {
        $value = $this->current($key);

        if ($value === null) {
            return null;
        }

        return str_repeat('•', 8).substr($value, -4).' ('.strlen($value).' characters)';
    }
}
