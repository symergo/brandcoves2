<?php

declare(strict_types=1);

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\ConnectorSetting;
use App\Services\Settings\AutomationSettingsStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the automation grid so the first deploy changes nothing.
 *
 * The switches decide which editorial stages run unattended, per market and per
 * kind. They ship as **data** rather than as a code default, and the difference
 * matters: a code default would make "what is running here" a question about
 * which release you are on, and the answer would change under somebody the
 * first time they deployed.
 *
 * What is seeded is what the scheduler already does:
 *
 *   - **`build`, every kind, every market.** Two scheduled jobs already build
 *     unattended and both are now gated by this switch: `BuildDailyEdition` at
 *     06:00 for the column, and `PublishDueCoves` at 07:00 for every approved
 *     non-daily plan whose date has arrived. Seeding `build` off for the kinds
 *     `PublishDueCoves` serves would silently stop every seasonal part
 *     publishing — which is precisely the deploy-day change this seed exists to
 *     prevent. `build` is also the *safe* switch: it publishes nothing that a
 *     person has not approved, because `buildArticle()` refuses anything else.
 *   - **`plan` and `write`, `daily` only.** The column's topics come from the
 *     observance calendar and its prose from the model under the daily cap,
 *     which is what `BuildDailyEdition` has always done.
 *   - **`approve`: off everywhere, including `daily`.** This is the only switch
 *     that removes a human, and nothing in the status quo does that: the Daily
 *     publishes from the calendar rather than from an approved plan, and every
 *     other kind refuses to build without one.
 *
 * `approve` is off for **every** kind including `daily` — the Daily does not
 * need it, because its build only consults a plan's status to decide whether an
 * approved one should override the calendar. Nothing in this seed can cause a
 * page to publish that would not have published yesterday.
 *
 * `curate` is off everywhere too. It is cheap and safe, and it is also the
 * stage that fills a shortlist somebody may be about to fill themselves; a
 * default that quietly curated every draft would take that decision away.
 *
 * ## Written through the model, deliberately
 *
 * `connector_settings.encrypted_value` is encrypted by a cast on
 * `ConnectorSetting` — everything in that table is, including the booleans, so
 * that no column is sometimes encrypted and sometimes not. A raw `DB::table()`
 * insert would store readable plaintext that the store then fails to decrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `automation` has to be a legal source first.
         *
         * The column is a string with a CHECK, per the convention in CLAUDE.md —
         * a native PG enum cannot be altered inside a transaction, which makes
         * every future value a deploy hazard. A CHECK can simply be replaced.
         */
        $sources = ['awin', 'bol', 'ebay', 'tradedoubler', 'amazon', 'manual', 'ai', 'ops', 'automation'];
        $list = implode(', ', array_map(fn (string $s) => "'{$s}'", $sources));

        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$list}))");

        foreach (Market::cases() as $market) {
            /*
             * Build, for every kind.
             *
             * `PublishDueCoves` already honours an approved plan of any kind on
             * the day it is due, so seeding this off would stop every seasonal
             * part publishing the moment this deploys.
             */
            foreach (CoveKind::cases() as $kind) {
                ConnectorSetting::query()->updateOrCreate(
                    [
                        'source' => AutomationSettingsStore::SOURCE,
                        'key' => AutomationSettingsStore::key('build', $market, $kind),
                    ],
                    ['encrypted_value' => '1'],
                );
            }

            // Planning and writing, for the column alone. Nothing else has ever
            // been drafted or written on a schedule.
            foreach (['plan' => '1', 'write' => 'builder'] as $stage => $value) {
                ConnectorSetting::query()->updateOrCreate(
                    [
                        'source' => AutomationSettingsStore::SOURCE,
                        'key' => AutomationSettingsStore::key($stage, $market, CoveKind::Daily),
                    ],
                    ['encrypted_value' => $value],
                );
            }
        }
    }

    public function down(): void
    {
        ConnectorSetting::query()->where('source', AutomationSettingsStore::SOURCE)->delete();

        $sources = ['awin', 'bol', 'ebay', 'tradedoubler', 'amazon', 'manual', 'ai', 'ops'];
        $list = implode(', ', array_map(fn (string $s) => "'{$s}'", $sources));

        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$list}))");
    }
};
