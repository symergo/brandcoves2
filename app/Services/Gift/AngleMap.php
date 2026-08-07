<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\Vibe;
use App\Jobs\WidenGiftAngles;
use App\Models\GiftAngle;

/**
 * Turns "they like photography and it should feel beautiful" into search terms.
 *
 * Two layers, and the order matters:
 *
 * 1. **Curated seed**, compiled into this class. Small, hand-written, and good
 *    enough on its own. It exists so the feature works on a fresh database, in
 *    a test, and with `AI_ENABLED=false` — a gift finder that returns nothing
 *    until a nightly job has run is a gift finder that is broken on launch day.
 * 2. **Widened rows** from `gift_angles`, written by {@see WidenGiftAngles}.
 *    These accumulate over time and are what makes the results stop feeling
 *    like a fixed list.
 *
 * The seed is in Dutch because two of five markets are Dutch and the Belgian
 * feeds are predominantly Dutch-titled; per-market overrides come from the
 * table. Postgres FTS stems per market anyway, so a Dutch query against a
 * French catalogue degrades to no matches rather than to wrong ones.
 */
class AngleMap
{
    /**
     * Base queries per interest, before vibe.
     *
     * Deliberately concrete product nouns, not themes. "cadeau voor fotograaf"
     * retrieves gift-guide listicles and junk; "statief", "cameratas" and
     * "polarisatiefilter" retrieve products.
     *
     * @var array<string, list<string>>
     */
    private const SEED = [
        'cooking' => ['kookboek', 'koksmes', 'pannenset', 'keukenmachine', 'snijplank', 'kruidenset', 'wok'],
        'coffee' => ['espressomachine', 'koffiemolen', 'french press', 'melkopschuimer', 'koffiebonen', 'aeropress'],
        'photography' => ['statief', 'cameratas', 'polarisatiefilter', 'objectief', 'fotolijst', 'instantcamera'],
        'music' => ['koptelefoon', 'platenspeler', 'bluetooth speaker', 'ukelele', 'microfoon', 'vinyl'],
        'gaming' => ['controller', 'gaming headset', 'bordspel', 'gaming muis', 'retro console', 'puzzel'],
        'reading' => ['e-reader', 'boekensteun', 'leeslamp', 'boekenlegger', 'notitieboek'],
        'fitness' => ['yogamat', 'dumbbells', 'sporthorloge', 'foam roller', 'weerstandsbanden', 'bidon'],
        'outdoors' => ['wandelrugzak', 'thermosfles', 'hoofdlamp', 'kampeerstoel', 'verrekijker', 'zakmes'],
        'travel' => ['handbagage koffer', 'reisadapter', 'paklijst organizer', 'nekkussen', 'powerbank'],
        'gardening' => ['snoeischaar', 'plantenbak', 'gieter', 'tuingereedschap set', 'kweekkas', 'zadenset'],
        'diy' => ['schroevendraaierset', 'accuboormachine', 'gereedschapskoffer', 'waterpas', 'multitool'],
        'beauty' => ['parfum', 'gezichtsverzorging set', 'haardroger', 'make-up kwasten', 'badset'],
        'fashion' => ['sjaal', 'horloge', 'zonnebril', 'leren riem', 'handschoenen', 'sieraden'],
        'tech' => ['smartwatch', 'draadloze oplader', 'slimme lamp', 'tablet', 'mechanisch toetsenbord'],
        'home' => ['geurkaars', 'plaid', 'wandklok', 'vaas', 'kussenhoes', 'sfeerverlichting'],
        'craft' => ['breipakket', 'aquarelverf', 'schetsboek', 'kalligrafie set', 'naaimachine', 'hobbymes'],
        'film' => ['beamer', 'soundbar', 'streaming stick', 'filmposter', 'popcornmachine'],
        'pets' => ['hondenmand', 'kattenkrabpaal', 'voerautomaat', 'hondenspeelgoed', 'huisdier fontein'],
        'wellness' => ['massageapparaat', 'aromadiffuser', 'badjas', 'geurstokjes', 'lichttherapielamp'],
        'kids' => ['bouwset', 'knuffel', 'kinderboek', 'buitenspeelgoed', 'educatief speelgoed'],
    ];

    /**
     * Queries for a set of interests, in priority order.
     *
     * Free-text interests (anything not in the enum) are passed through as
     * queries verbatim: someone who typed "wielrennen" has told us exactly what
     * to search for, and second-guessing them is worse than trusting them.
     *
     * @param  list<string>  $interests  enum values and/or free text
     * @return list<string>
     */
    public function queriesFor(Market $market, array $interests, ?Vibe $vibe = null): array
    {
        if ($interests === []) {
            return [];
        }

        $widened = $this->widened($market, $interests, $vibe);
        $queries = [];

        foreach ($interests as $interest) {
            $key = mb_strtolower(trim($interest));

            if ($key === '') {
                continue;
            }

            /*
             * Widened rows first. They are newer and more specific, and putting
             * them behind the seed would mean the nightly job never visibly
             * changes anything until the seed runs out.
             */
            foreach ($widened[$key] ?? [] as $query) {
                $queries[] = $query;
            }

            if (Interest::tryFrom($key) !== null) {
                foreach (self::SEED[$key] ?? [] as $query) {
                    $queries[] = $query;
                }
            } else {
                // Free text. Trusted as written.
                $queries[] = $key;
            }
        }

        // Preserve first-seen order: the first interest someone picked is the
        // one they thought of first, and it should shape the results most.
        return array_values(array_unique($queries));
    }

    /**
     * Stored expansions, keyed by interest.
     *
     * A row with a matching vibe beats the "any vibe" row, and both are used:
     * vibe narrows the flavour without discarding the reliable general queries.
     *
     * @param  list<string>  $interests
     * @return array<string, list<string>>
     */
    private function widened(Market $market, array $interests, ?Vibe $vibe): array
    {
        $rows = GiftAngle::query()
            ->forMarket($market)
            ->whereIn('interest', array_map(fn (string $i) => mb_strtolower(trim($i)), $interests))
            ->where(function ($q) use ($vibe): void {
                $q->whereNull('vibe');

                if ($vibe !== null) {
                    $q->orWhere('vibe', $vibe->value);
                }
            })
            // Vibe-specific rows last in the sort so they land first after the
            // reverse below — NULLS FIRST is clearer than juggling two queries.
            ->orderByRaw('vibe IS NULL DESC')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            foreach ((array) $row->queries as $query) {
                $query = trim((string) $query);

                if ($query !== '') {
                    $map[$row->interest][] = $query;
                }
            }
        }

        return array_map(fn (array $q) => array_values(array_unique($q)), $map);
    }

    /**
     * The curated seed for one interest, for the widening job to build on.
     *
     * @return list<string>
     */
    public function seedFor(Interest $interest): array
    {
        return self::SEED[$interest->value] ?? [];
    }
}
