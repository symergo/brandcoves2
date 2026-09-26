<?php

declare(strict_types=1);

namespace App\Services\Identity;

/**
 * Model numbers found in a product title: `42125`, `WH-1000XM5`, `SM-S921B`.
 *
 * Used only to PROPOSE that two products are one (FindMatchCandidates), never
 * to merge them: a person confirms every pair. So the job here is to find the
 * token a manufacturer uses to name one product, and to stay away from the
 * tokens every product in a category shares: sizes, capacities, years,
 * resolutions, connector standards. A token that slips through costs a person
 * one "Not the same" click; a token that is missed costs a merge nobody sees.
 * The rules lean to the first kind of miss being rarer, because a queue full
 * of `500ml` pairs would stop being read.
 *
 * Pure and static so it is unit-tested on real titles (tests/Unit/
 * ModelNumberTest.php) and so the review page can say which token matched.
 */
final class ModelNumber
{
    /**
     * Units that turn a number into a measurement. Matched after a number, with
     * or without a space having been there: "500ml", "42mm", "10000mAh",
     * "65w", "2tb", "3x", "100stuks". Covers the four languages the catalogue
     * is in, because the quantity words are what differ.
     */
    private const UNITS = 'ml|cl|dl|l|ltr|liter|litre|g|gr|gram|grams|kg|mg|mm|cm|dm|m|km|in|inch|ft|oz|lb|lbs'
        .'|gb|tb|mb|kb|w|kw|v|mah|wh|hz|khz|mhz|ghz|db|a|mp|k|x|p|st|stk|stuks|pcs|pc|pack|delig|pieces'
        .'|pers|personen|persons|jaar|jaren|years|yrs|ans|anos|mois|maanden|months|dagen|days|cups|kopjes|tassen'
        .'|rpm|bar|lm|lumen|nits|ppm|dpi|fps|min|h|u|s|sec';

    /**
     * Prefixes that name a standard, not a product: `USB3`, `WiFi6`, `HDMI21`,
     * `DDR5`, `IP68`, `PCIe4`, `Gen2`. Every brand's products carry them.
     * `RTX`/`GTX`/`RX` are graphics chips, shared by every card built on one.
     */
    private const STANDARDS = '/^(usb|wifi|hdmi|bt|bluetooth|ddr|lpddr|gddr|pcie|gen|ipx|ip|dect|lte|cat|type|qi|rtx|gtx|rx|dp|ax|ac|nvme|sata|rj)\d/';

    /**
     * Every model number in the title, normalised (upper case, no hyphens,
     * dots, slashes or spaces), first mention first.
     *
     * @return list<string>
     */
    public static function extract(string $title): array
    {
        $found = [];

        // Split on everything but the characters a model number is written
        // with. Hyphen, dot and slash stay inside a token ("WH-1000XM5",
        // "SM-S921B/DS") and are removed by normalise().
        $tokens = preg_split('/[^\p{L}\p{N}\-.\/#]+/u', mb_strtolower($title)) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token, '-./#');

            if ($token === '' || ! self::isModelNumber($token)) {
                continue;
            }

            // A list, not array keys: PHP turns the key "42125" into an int.
            $model = self::normalise($token);

            if (! in_array($model, $found, true)) {
                $found[] = $model;
            }
        }

        return $found;
    }

    /**
     * The form two model numbers are compared in. Also used on a feed's own
     * `mpn`, so "WH-1000XM5" from a title and "WH1000XM5" from Awin meet.
     */
    public static function normalise(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $raw));
    }

    /**
     * A feed-supplied part number, if it is usable as one.
     *
     * The same shape test as a title token, minus the unit and standard
     * checks: a feed that fills `mpn` means it as a part number. Anything that
     * could be a barcode (8 or more digits and nothing else) is refused,
     * because some advertisers copy the EAN into this column and a barcode
     * already has its own, stricter rule.
     */
    public static function fromMpn(?string $mpn): ?string
    {
        if ($mpn === null) {
            return null;
        }

        $value = self::normalise($mpn);

        if (strlen($value) < 4 || strlen($value) > 40 || ! preg_match('/\d/', $value)) {
            return null;
        }

        if (preg_match('/^\d{8,}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Whether two titles' numbers could describe one product: every token with
     * a digit in one title also appears in the other, in one direction or the
     * other.
     *
     * Measured on a copy of the catalogue (2026-09-26): nearly every pair the
     * similar-title rule proposed without this was two products one digit
     * apart. "Galaxy A27 128GB" beside "Galaxy A17 128GB", "Cadeaubon 25
     * euro" beside "75 euro", "SX-53" beside "SX-33". Titles that differ in a
     * number almost always name different things; titles where one only adds
     * a number ("LEGO Ferrari 488" and "LEGO Ferrari 488 #42125") may not.
     */
    public static function numbersAgree(string $a, string $b): bool
    {
        $x = self::numberTokens($a);
        $y = self::numberTokens($b);

        return array_diff($x, $y) === [] || array_diff($y, $x) === [];
    }

    /** @return list<string> */
    private static function numberTokens(string $title): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}\-.\/#]+/u', self::fold($title)) ?: [];
        $out = [];

        foreach ($tokens as $token) {
            $plain = (string) preg_replace('/[^a-z0-9]+/', '', $token);

            if ($plain !== '' && preg_match('/\d/', $plain)) {
                $out[] = $plain;
            }
        }

        return array_values(array_unique($out));
    }

    private static function isModelNumber(string $token): bool
    {
        $plain = (string) preg_replace('/[^a-z0-9]+/u', '', self::fold($token));

        if ($plain === '') {
            return false;
        }

        // Only digits: a LEGO set (42125), an IKEA-like article number. Five
        // to seven digits. Four or fewer are years, wattages, sizes ("2024",
        // "1000"); eight or more are barcodes or shop article numbers, which
        // are not the manufacturer's name for the thing.
        //
        // A dot or comma between the digits makes it a price or a decimal
        // ("129.99", "1.500"), never a set number.
        if (ctype_digit($plain)) {
            return strlen($plain) >= 5 && strlen($plain) <= 7 && ! preg_match('/[.,]/', $token);
        }

        // Letters and digits mixed. At least five characters and two digits:
        // "XM5", "PS5", "S24" and "4K" are series, consoles and features that
        // many products share; "WH1000XM5" and "SMS921B" are one product.
        if (! preg_match('/[a-z]/', $plain) || strlen($plain) < 5 || preg_match_all('/\d/', $plain) < 2) {
            return false;
        }

        // Longer than any real model number: a URL fragment or glued words.
        if (strlen($plain) > 20) {
            return false;
        }

        // Hyphens out for the shape tests, so "10-pack", "8-12jaar" and
        // "100-240v" read as the number-with-unit they are.
        $lower = str_replace('-', '', self::fold($token));

        // A number with a unit: "500ml", "10000mah", "2,5kg", "65w".
        if (self::isMeasurement($lower)) {
            return false;
        }

        // Rates and spec pairs: "150mb/s", "10km/h", "24gb/1tb". Found on a
        // copy of the catalogue, where memory cards of one speed class were
        // proposed as one product across every capacity.
        if (str_contains($lower, '/')) {
            $parts = array_filter(explode('/', $lower), fn (string $p) => $p !== '');
            $measured = array_filter($parts, fn (string $p) => self::isMeasurement($p) || preg_match('/^[a-z]{1,3}$/', $p));

            if (count($measured) === count($parts)) {
                return false;
            }
        }

        // Dimensions and multiples: "30x40", "30x40cm", "6x500ml", "1920x1080".
        if (preg_match('/^\d+([.,]\d+)?x\d+([.,]\d+)?([a-z]+)?$/', $lower)) {
            return false;
        }

        // "2in1", "3-in-1".
        if (preg_match('/^\d+in\d+$/', $lower)) {
            return false;
        }

        // Resolutions and ordinals: "1080p", "2160p", "4th", "10de", "3eme".
        if (preg_match('/^\d{3,4}[pi]$/', $lower) || preg_match('/^\d+(st|nd|rd|th|de|ste|e|eme|er)$/', $lower)) {
            return false;
        }

        // A standard rather than a product: "usb3.2", "wifi6e", "ip68".
        if (preg_match(self::STANDARDS, $plain)) {
            return false;
        }

        return true;
    }

    private static function isMeasurement(string $lower): bool
    {
        return (bool) preg_match('/^\d+([.,]\d+)?('.self::UNITS.')$/', $lower);
    }

    /** Lower case and no accents, so "LÉGO" and "lego" test the same. */
    private static function fold(string $value): string
    {
        $value = mb_strtolower($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $ascii === false ? $value : (string) preg_replace('/[\'"^~`]/', '', $ascii);
    }
}
