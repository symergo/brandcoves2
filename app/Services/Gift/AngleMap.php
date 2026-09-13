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
        // The sixteen added 2026-09-14. Same rule: concrete product nouns.
        'art' => ['acrylverf set', 'schildersezel', 'aquarelset', 'tekenset', 'penselen set', 'canvas doek'],
        'cycling' => ['fietslamp', 'fietshelm', 'fietscomputer', 'fietstas', 'fietsbidon', 'fietsgereedschap'],
        'boardgames' => ['bordspel', 'kaartspel', 'strategiespel', 'partyspel', 'dobbelspel', 'puzzel 1000 stukjes'],
        'drinks' => ['wijnset', 'decanteerkaraf', 'gin set', 'whiskyglazen', 'cocktailset', 'kurkentrekker'],
        'baking' => ['bakvorm', 'keukenweegschaal', 'springvorm', 'spuitzak set', 'bakboek', 'taartplateau'],
        'running' => ['hardloophorloge', 'hardloopriem', 'reflecterend vest', 'hartslagmeter', 'sportsokken', 'hardloopjack'],
        'yoga' => ['yogablok', 'meditatiekussen', 'yogariem', 'yogatas', 'meditatiebankje', 'yogaboek'],
        'cars' => ['dashcam', 'autostofzuiger', 'telefoonhouder auto', 'modelauto', 'auto poetsset', 'startkabels'],
        'science' => ['telescoop', 'microscoop', 'experimenteerdoos', 'sterrenkaart', 'planetarium projector', 'wetenschapsboek'],
        'water' => ['zwembril', 'snorkelset', 'waterdichte tas', 'sup board', 'duikhorloge', 'microvezel handdoek'],
        'wintersports' => ['skibril', 'skihandschoenen', 'thermo ondergoed', 'skisokken', 'skihelm', 'snowboard onderhoud'],
        'football' => ['voetbal', 'scheenbeschermers', 'voetbalschoenen', 'keepershandschoenen', 'voetbalshirt', 'trainingshesjes'],
        'collecting' => ['funko pop', 'verzamelfiguur', 'lego set', 'modelbouw', 'ruilkaarten', 'vitrinekast'],
        'nature' => ['vogelhuisje', 'vogelvoeder', 'natuurgids', 'insectenhotel', 'wildcamera', 'vogelverrekijker'],
        'fishing' => ['hengel', 'visdoos', 'vismolen', 'viskoffer', 'visstoel', 'kunstaas set'],
        'horses' => ['paardenborstel', 'rijhandschoenen', 'halster', 'ruiterhelm', 'hoefkrabber', 'paardenboek'],
        'hunting' => ['jachtmes', 'verrekijker jacht', 'jachtvest', 'wildlokker', 'jachtrugzak', 'schietbril'],
    ];

    /**
     * Queries for a set of interests, in priority order.
     *
     * The flat form of {@see queriesByInterest()}, for retrieval, which only
     * needs the terms.
     *
     * @param  list<string>  $interests  enum values and/or free text
     * @return list<string>
     */
    public function queriesFor(Market $market, array $interests, ?Vibe $vibe = null): array
    {
        $queries = [];

        foreach ($this->queriesByInterest($market, $interests, $vibe) as $slot) {
            foreach ($slot['queries'] as $query) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    /**
     * Queries grouped by the interest they came from, interests in the order
     * they were given.
     *
     * The grouping is what lets the scorer weigh *which interest* a product
     * answers rather than which query in a flat list it happened to match.
     * Flattened, the second interest's first query sat right behind the first
     * interest's last, and a speaker answering "tech" scored nearly as well as
     * a paint set answering "schilderen" — the interest the person typed first.
     *
     * Free-text interests (anything not in the enum) are passed through as
     * queries verbatim: someone who typed "wielrennen" has told us exactly what
     * to search for, and second-guessing them is worse than trusting them.
     *
     * A query that two interests share is credited to the first; it appears
     * once, so retrieval's tsquery does not grow with duplicates.
     *
     * @param  list<string>  $interests  enum values and/or free text
     * @return list<array{interest: string, queries: list<string>}>
     */
    public function queriesByInterest(Market $market, array $interests, ?Vibe $vibe = null): array
    {
        if ($interests === []) {
            return [];
        }

        $widened = $this->widened($market, $interests, $vibe);
        $seen = [];
        $slots = [];

        foreach ($interests as $interest) {
            $key = mb_strtolower(trim($interest));

            if ($key === '') {
                continue;
            }

            $queries = [];

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

            $own = [];

            foreach ($queries as $query) {
                if (! isset($seen[$query])) {
                    $seen[$query] = true;
                    $own[] = $query;
                }
            }

            if ($own !== []) {
                $slots[] = ['interest' => $key, 'queries' => $own];
            }
        }

        return $slots;
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
