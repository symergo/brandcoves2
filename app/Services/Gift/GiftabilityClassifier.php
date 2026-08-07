<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * Decides whether a catalogue row is something you could actually give someone.
 *
 * Pure: text and a price in, a verdict out. No database, no network, no market
 * lookup. That is deliberate — this is where the subtle bugs live, and a pure
 * function can be pinned down by a golden file.
 *
 * The problem it solves: a merchant feed is mostly *not* gifts. Vacuum bags,
 * printer toner, extended warranties, phone cases for one specific handset and
 * replacement filters vastly outnumber the things a person would be pleased to
 * unwrap. One of those in a gift result destroys trust in every other result on
 * the page, so the classifier is tuned to be strict: a wrongly excluded gift
 * costs one candidate out of tens of thousands, a wrongly included non-gift
 * costs the feature's credibility.
 *
 * ## Two decisions that shape everything below
 *
 * **1. Match substrings, not words.** Dutch and German write compounds closed:
 * "stofzuigerzak", "inktcartridge", "waterfilterpatroon". A `\bcartridge\b`
 * regex matches none of them, so a word-boundary matcher waves every Dutch
 * consumable straight through — and Dutch is two of our five markets.
 *
 * **2. List the compound, never the bare stem.** The obvious follow-up mistake
 * is to add `filter` to the disqualifying list, which then also kills
 * "polarisatiefilter" and "ND-filter" — real presents for someone who takes
 * photographs. So the list holds `waterfilter`, `stofzuigerfilter`,
 * `filterpatroon`: the compounds that *identify* a consumable. Camera filters
 * survive because nothing in the list is a substring of them, not because of a
 * special case bolted on afterwards.
 *
 * Where a compound is still ambiguous — a replacement filter really can be a
 * lens filter — a rescue list carries the exception.
 */
class GiftabilityClassifier
{
    /**
     * Below this a gift reads as an afterthought, above it as an obligation.
     * Cents. Defaults mirror config('brandcoves.gift'); injected so the
     * classifier itself stays free of framework lookups.
     */
    public function __construct(
        private int $minPrice = 500,
        private int $maxPrice = 50000,
    ) {}

    /**
     * Terms that mean "this is not a gift", grouped by why.
     *
     * Languages are mixed in one table rather than split per market on purpose:
     * a Belgian feed puts Dutch, French and English in the same title field
     * routinely, and splitting would require knowing the language of an
     * individual *title*, which we do not.
     *
     * @var array<string, list<string>>
     */
    private const DISQUALIFYING = [
        // Bought to keep a machine you already own running.
        'consumable' => [
            /*
             * Never list a term alongside its own prefix. "navul" and
             * "navulling" both match a refill pack, but the shorter one is
             * reached second and carries no rescue of its own, so it would
             * quietly overturn the longer one's rescue and disqualify a coffee
             * hamper. Keep the prefix, hang the rescue on it.
             */
            'cartridge', 'toner', 'inktpatroon', 'inkjet', 'navul',
            'refill', 'recharge', 'recambio',
            'stofzuigerzak', 'dust bag', 'sac aspirateur', 'bolsa aspirador',
            'ontkalker', 'descaler', 'detartrant', 'descalcificador',
            'wasmiddel', 'detergent', 'lessive', 'detergente',
            'vaatwastablet', 'dishwasher tablet', 'pastilla lavavajillas',
            // Compounds, never the bare stem — see the class docblock.
            'waterfilter', 'luchtfilter', 'stofzuigerfilter', 'afzuigkapfilter',
            'filterzak', 'filterpatroon', 'koolstoffilter', 'hepa',
            'vervangingsfilter', 'filtre de rechange', 'replacement filter',
        ],

        // Parts. A gift is a whole thing.
        'spare_part' => [
            'reserveonderdeel', 'vervangingsonderdeel',
            'spare part', 'replacement part', 'piece de rechange', 'repuesto',
            'ersatzteil', 'borstel voor', 'brush for',
            'accupack', 'vervangende accu', 'replacement battery',
        ],

        // Not an object at all.
        'service' => [
            'garantieverlenging', 'extended warranty', 'extension de garantie',
            'garantia extendida', 'servicecontract', 'service plan',
            'installatieservice', 'installation service', 'montageservice',
            'abonnement', 'subscription', 'suscripcion',
            'licentie', 'license key', 'licencia', 'activatiecode',
            'verzekering', 'insurance', 'assurance', 'seguro',
            'retourlabel', 'verzendkosten', 'shipping cost', 'frais de port',
        ],

        /*
         * Fitment. A phone case is a fine gift only if you know exactly which
         * handset they carry — and if you knew that, you would not need us.
         *
         * "geschikt voor" / "compatible with" is the merchant telling you
         * plainly that this works with one specific thing and nothing else.
         */
        'fitment' => [
            'geschikt voor', 'compatible with', 'compatible avec', 'compatible con',
            'passend voor', 'hoesje voor', 'case for', 'coque pour', 'funda para',
            'screenprotector', 'screen protector', 'beschermfolie',
            'protector de pantalla', 'film de protection',
        ],

        // Necessities. Nobody unwraps forty rolls of anything with delight.
        'household_staple' => [
            'toiletpapier', 'toilet paper', 'papier toilette', 'papel higienico',
            'keukenrol', 'kitchen roll', 'essuie-tout',
            'vuilniszak', 'bin bag', 'sac poubelle', 'bolsa de basura',
            'batterijen aa', 'aa batteries', 'aaa batteries',
        ],
    ];

    /**
     * Phrases that rescue one specific term from its group.
     *
     * Keyed by the term, not by the group. Rescuing at group level would let a
     * lens filter drag printer toner in behind it.
     *
     * @var array<string, list<string>>
     */
    private const RESCUES = [
        // A replacement filter really can be a lens filter.
        'vervangingsfilter' => ['lens', 'objectief', 'camera', 'polarisatie'],
        'replacement filter' => ['lens', 'camera', 'polarising', 'polarizing'],
        'filtre de rechange' => ['objectif', 'appareil photo'],

        // A battery pack for a drill is a spare; a power bank is a present.
        'accupack' => ['powerbank', 'power bank'],
        'vervangende accu' => ['powerbank', 'power bank'],
        'replacement battery' => ['powerbank', 'power bank'],

        // A gift set built around consumables is a gift — the packaging is the
        // product. A coffee hamper is not a bag of pods.
        'navul' => ['cadeau', 'geschenk', 'gift set', 'coffret', 'set de regalo'],
        'refill' => ['gift set', 'cadeau', 'coffret', 'set de regalo'],
    ];

    /**
     * A multipack of something mundane.
     *
     * Structural rather than lexical: the giveaway is a count, and counts are
     * written a hundred ways per language but always as a number beside a unit
     * noun. Two digits and up — a "3-delige set" is a normal product, a
     * "50 stuks" is a supply run.
     */
    private const BULK_PATTERNS = [
        '/\b\d{2,}\s*(stuks|stuk|pack|pcs|units|unidades|pieces)\b/u',
        '/\b(pak|set|doos|box|lot)\s+van\s+\d{2,}\b/u',
        '/\b\d{2,}[\s\-]?(pack|delig|teilig)\b/u',
    ];

    /**
     * Words that say "this was made to be given".
     *
     * Only ever rescues a borderline row, never promotes one. A merchant
     * writing "cadeautip" on a box of dishwasher tablets should not win an
     * argument the rest of the classifier has already settled.
     *
     * @var list<string>
     */
    private const GIFT_MARKERS = [
        'cadeau', 'geschenk', 'gift set', 'giftset', 'coffret', 'regalo',
        'kado', 'cadeaupakket', 'proefpakket', 'gift box',
    ];

    public function classify(
        string $title,
        ?string $category = null,
        ?int $priceCents = null,
    ): Giftability {
        $haystack = $this->normalise($title.' '.($category ?? ''));

        if ($haystack === '') {
            return Giftability::no('no_title');
        }

        /*
         * Price first: cheapest check, least arguable. A €2 item is not a gift
         * whatever its title claims, and a €900 one is a decision rather than a
         * suggestion — and this feature exists to make suggestions.
         */
        if ($priceCents === null) {
            return Giftability::no('no_price');
        }

        if ($priceCents < $this->minPrice) {
            return Giftability::no('too_cheap');
        }

        if ($priceCents > $this->maxPrice) {
            return Giftability::no('too_expensive');
        }

        foreach (self::DISQUALIFYING as $reason => $terms) {
            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    continue;
                }

                if ($this->isRescued($term, $haystack)) {
                    continue;
                }

                return Giftability::no($reason, $term);
            }
        }

        foreach (self::BULK_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack) === 1 && ! $this->hasGiftMarker($haystack)) {
                return Giftability::no('bulk');
            }
        }

        return Giftability::yes();
    }

    private function isRescued(string $term, string $haystack): bool
    {
        foreach (self::RESCUES[$term] ?? [] as $rescue) {
            if (str_contains($haystack, $rescue)) {
                return true;
            }
        }

        return false;
    }

    private function hasGiftMarker(string $haystack): bool
    {
        foreach (self::GIFT_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accent folding, done by table rather than by iconv.
     *
     * `iconv('UTF-8', 'ASCII//TRANSLIT')` produces different output on glibc,
     * musl and Windows — "é" becomes "e", "'e" or "?" depending on the host.
     * Tests run on a Windows laptop and this code runs on Alpine in production;
     * a classifier that disagrees with its own test suite across platforms is
     * worse than no classifier.
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ø' => 'o', 'æ' => 'ae',
    ];

    /**
     * Lowercase, fold accents, reduce everything else to single spaces.
     *
     * Accents are folded because feeds are inconsistent about them inside a
     * single file — "pièce" and "piece" both appear in the same advertiser's
     * rows. Punctuation collapses to a space so "ND-filter", "ND filter" and
     * "ND/filter" normalise alike; digits survive because the bulk patterns
     * need them.
     */
    private function normalise(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), self::FOLD);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
