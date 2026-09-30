<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The brand of an offer whose source named none, read from the start of its title.
 *
 * ## Why (2026-09-30)
 *
 * 42% of the catalogue had no brand: bol's catalogue API sends none at all, and
 * eBay listings rarely do. A product without a brand is missing from its brand
 * page, from brand search and from the brand facet. The owner asked for a fill.
 *
 * ## The rule, and why it is safe enough
 *
 * The title must START with a brand that some source has already named, on at
 * least `MIN_OFFERS` offers. Both halves matter:
 *
 * - **Start, not contain.** bol titles read "Brand Model, description"; the
 *   accessories that would be misfiled read the other way round ("Hoesje voor
 *   Sony WH-1000XM5" is somebody else's case). The same anchor
 *   `Search\BrandAttribution` uses on brand pages, for the same reason.
 * - **A brand a source named.** We never invent a brand from a title's first
 *   word ("Dames", "Set", "Mini"); we only recognise one the catalogue already
 *   holds. That caps what this can fill (measured on a copy of production: 18,928
 *   of 177,454 unbranded groups, 11%), and a sample of 40 was right 40 times.
 *
 * The longest brand wins ("Philips Hue" before "Philips"), and the brand must end
 * at a word boundary, so "Apple" does not claim "Applesauce". Comparison is on
 * `Str::ascii()`-folded text: "Kärcher" and "KARCHER" are one brand.
 *
 * ## What it must not touch
 *
 * Identity. For an offer without a barcode the brand is part of the grouping key
 * (IdentityResolver), so filling it in before identity is resolved would regroup
 * products. `OfferUpserter` resolves identity from the brand the source sent and
 * only then fills the stored brand from here. See docs/features/brand-fill.md.
 */
class TitleBrand
{
    /** A brand counts once sources named it on this many offers: fewer is often a typo. */
    public const MIN_OFFERS = 5;

    /** How long the list of known brands is kept. It moves slowly. */
    private const TTL = 86400;

    /**
     * What some feeds write when they have no brand. Never a brand.
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = [
        'unbranded', 'generic', 'merkloos', 'no brand', 'nobrand', 'sans marque', 'sin marca',
        'onbekend', 'unknown', 'n/a', 'na', 'none', 'other', 'others', 'various', 'diverse',
        'divers', 'huismerk', 'private label', 'does not apply', 'ne s\'applique pas', 'no aplica',
    ];

    /** @var array<string, list<array{0: string, 1: string}>>|null first word => [[folded, display], ...], longest first */
    private ?array $index = null;

    /**
     * @param  array<string, string>|null  $known  folded name => display name; the catalogue's when null
     */
    public function __construct(private ?array $known = null) {}

    public function infer(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        $folded = self::fold($title);
        $first = preg_split('/[^a-z0-9&.\'+]+/', $folded, 2)[0] ?? '';

        foreach ($this->index()[$first] ?? [] as [$brand, $display]) {
            if (! str_starts_with($folded, $brand)) {
                continue;
            }

            $next = substr($folded, strlen($brand), 1);

            if ($next === '' || ! ctype_alnum($next)) {
                return $display;
            }
        }

        return null;
    }

    public static function fold(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->known ?? self::catalogueBrands() as $folded => $display) {
            $first = preg_split('/[^a-z0-9&.\'+]+/', $folded, 2)[0] ?? '';

            if ($first !== '') {
                $index[$first][] = [$folded, $display];
            }
        }

        foreach ($index as &$brands) {
            usort($brands, fn (array $a, array $b) => strlen($b[0]) <=> strlen($a[0]));
        }

        return $this->index = $index;
    }

    /**
     * Brands sources named, folded => the most-used spelling ("Audio-Technica"
     * over "Audio Technica"), the rule `RefreshBrandStats` uses for a brand page.
     *
     * @return array<string, string>
     */
    public static function catalogueBrands(): array
    {
        return Cache::remember('bc:title-brand:known', self::TTL, function (): array {
            $known = [];

            DB::table('products')
                ->select('brand', DB::raw('count(*) as total'))
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->groupBy('brand')
                ->havingRaw('count(*) >= ?', [self::MIN_OFFERS])
                ->orderByDesc('total')
                ->orderBy('brand')
                ->get()
                ->each(function (object $row) use (&$known): void {
                    $folded = self::fold((string) $row->brand);

                    // Three characters at least: "LG" and "HP" would also start
                    // too many titles that are not theirs ("HP-kabel", "LG-lamp").
                    if (strlen($folded) >= 3 && ! in_array($folded, self::PLACEHOLDERS, true)) {
                        $known[$folded] ??= (string) $row->brand;
                    }
                });

            return $known;
        });
    }
}
