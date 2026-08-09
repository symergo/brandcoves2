<?php

declare(strict_types=1);

namespace App\Services\Seo;

use GdImage;
use RuntimeException;

/**
 * The 1200×630 card a link turns into when someone shares it.
 *
 * Drawn with GD rather than assembled from a template image, because the text is
 * the whole point: a product title, a guide's question, a Cove's theme. A static
 * picture with the logo on it is what we had, and it says nothing about the page.
 *
 * ## Typographic, never photographic
 *
 * No product photography goes in these cards, and that is a rule rather than a
 * simplification.
 *
 * An OG image is fetched and **cached by every platform that renders it** —
 * Facebook, WhatsApp, Slack, X, and whatever comes next. Compositing a
 * merchant's product photo into one and serving it from our domain is mirroring
 * their image, indefinitely, on infrastructure we do not control. For Amazon
 * that breaks invariant 6 outright; for everyone else it is a licence question
 * nobody has answered. A hotlinked photo is also a third-party fetch inside our
 * request, which is the thing the Amazon link parser refuses to do for the same
 * reasons.
 *
 * So: the mark, the type, and the numbers we computed ourselves.
 *
 * ## Text comes from records, never from the request
 *
 * Callers pass an entity; the controller reads its title. Nothing here renders a
 * string taken from a query parameter. An endpoint that draws arbitrary words on
 * a Brandcoves-branded card is an impersonation tool with a URL — anyone could
 * post a screenshot of "Brandcoves says …" that our own domain would serve.
 */
class OgImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Deep teal, sand, amber: the mark's own palette. */
    private const INK = [0x12, 0x23, 0x2B];

    private const SAND = [0xEF, 0xE6, 0xD6];

    private const AMBER = [0xF2, 0xA9, 0x3B];

    private const MARGIN = 72;

    /**
     * @param  string  $title  the headline, wrapped over at most three lines
     * @param  string|null  $kicker  small amber label above it ("Buying guide")
     * @param  string|null  $footnote  the line along the bottom ("14 shops · from €329")
     * @return string PNG bytes
     */
    public function render(string $title, ?string $kicker = null, ?string $footnote = null): string
    {
        $this->assertFontsUsable();

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagefill($canvas, 0, 0, $this->colour($canvas, self::INK));

        $this->drawGlow($canvas);
        $this->drawMark($canvas, self::MARGIN, self::MARGIN, 64);
        $this->drawWordmark($canvas);

        $bottom = self::HEIGHT - self::MARGIN;

        if ($footnote !== null && $footnote !== '') {
            $this->text($canvas, $footnote, self::MARGIN, $bottom, 26, self::SAND, self::regular(), 0.72);
            $bottom -= 58;
        }

        // The amber rule sits directly under the headline block and above the
        // footnote, so the two never collide however many lines the title takes.
        $this->rule($canvas, self::MARGIN, $bottom - 30, 96);

        $this->drawTitle($canvas, $title, $kicker, $bottom - 74);

        ob_start();
        imagepng($canvas, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        return $png;
    }

    /**
     * The headline block, laid out upwards from the rule.
     *
     * Bottom-anchored rather than top-anchored: a one-line title and a
     * three-line title then share the same baseline above the rule, instead of
     * a short title leaving a hole in the middle of the card.
     */
    private function drawTitle(GdImage $canvas, string $title, ?string $kicker, int $baseline): void
    {
        $font = self::bold();
        $size = 60;
        $maxWidth = self::WIDTH - (self::MARGIN * 2);

        /*
         * Shrink before truncating. Feeds produce eighty-character titles
         * routinely, and one reads better at 42pt over three lines than clipped
         * at 60pt — this is the only type on the site that cannot reflow to fit
         * its container.
         *
         * Measured against a generous line cap, so `wrap()` reports how many
         * lines the title actually wants rather than how many it is allowed.
         */
        while ($size > 42 && count($this->wrap($title, $font, $size, $maxWidth, 8)) > 3) {
            $size -= 6;
        }

        $lines = $this->wrap($title, $font, $size, $maxWidth, 3);

        $leading = (int) round($size * 1.22);
        $y = $baseline;

        foreach (array_reverse($lines) as $line) {
            $this->text($canvas, $line, self::MARGIN, $y, $size, self::SAND, $font);
            $y -= $leading;
        }

        if ($kicker !== null && $kicker !== '') {
            $this->text($canvas, mb_strtoupper($kicker), self::MARGIN, $y - 4, 24, self::AMBER, self::semibold(), spacing: 2.0);
        }
    }

    /**
     * The cove, drawn rather than loaded.
     *
     * The same geometry as `public/icons/brandcoves.svg`, minus the tile: the
     * card is already the tile's colour, so the headland and the buoy sit
     * straight on the background. Loading the SVG would need a rasteriser GD
     * does not have, and the PNG would mean resampling a bitmap that is the
     * wrong size for this card.
     *
     * The angles are the part worth writing down. In the SVG the arc runs from
     * (40.6, 19.71) to (40.6, 44.29) the long way round a centre at (32, 32),
     * which is −55° to +55° with the gap facing right. GD measures clockwise
     * from three o'clock on a y-down canvas, so the same sweep is 55° → 305°.
     */
    private function drawMark(GdImage $canvas, int $x, int $y, int $size): void
    {
        $scale = $size / 64;
        $cx = $x + (int) round(32 * $scale);
        $cy = $y + (int) round(32 * $scale);
        $diameter = (int) round(30 * $scale);

        imagesetthickness($canvas, max(2, (int) round(8.5 * $scale)));
        imagearc($canvas, $cx, $cy, $diameter, $diameter, 55, 305, $this->colour($canvas, self::SAND));
        imagesetthickness($canvas, 1);

        imagefilledellipse(
            $canvas,
            $x + (int) round(44 * $scale),
            $cy,
            (int) round(10 * $scale),
            (int) round(10 * $scale),
            $this->colour($canvas, self::AMBER),
        );
    }

    private function drawWordmark(GdImage $canvas): void
    {
        $this->text(
            $canvas,
            'Brandcoves',
            self::MARGIN + 84,
            self::MARGIN + 44,
            32,
            self::SAND,
            self::semibold(),
            0.85,
        );
    }

    /**
     * A soft amber wash in the far corner.
     *
     * Concentric translucent ellipses rather than a real gradient, which GD has
     * no primitive for. Cheap, and it keeps the card from reading as a flat
     * rectangle in a timeline full of photographs.
     */
    private function drawGlow(GdImage $canvas): void
    {
        $colour = imagecolorallocatealpha(
            $canvas,
            self::AMBER[0],
            self::AMBER[1],
            self::AMBER[2],
            // 124 of 127. Barely there per ring; they accumulate toward the
            // centre, which is what makes the falloff look like a gradient.
            124,
        );

        for ($i = 18; $i > 0; $i--) {
            imagefilledellipse($canvas, self::WIDTH - 30, 40, $i * 44, $i * 44, $colour);
        }
    }

    private function rule(GdImage $canvas, int $x, int $y, int $width): void
    {
        imagefilledrectangle($canvas, $x, $y, $x + $width, $y + 5, $this->colour($canvas, self::AMBER));
    }

    /**
     * Break a string into lines that fit, discarding the overflow.
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->width($candidate, $font, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            $current = $word;

            if (count($lines) === $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        if ($lines === []) {
            return [];
        }

        // An ellipsis only where something was actually dropped.
        $rendered = implode(' ', $lines);

        if (mb_strlen($rendered) < mb_strlen(trim($text))) {
            $last = array_pop($lines);

            while ($last !== null && $this->width($last.'…', $font, $size) > $maxWidth && str_contains($last, ' ')) {
                $last = mb_substr($last, 0, (int) mb_strrpos($last, ' '));
            }

            $lines[] = $last.'…';
        }

        return array_values($lines);
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : (int) ($box[2] - $box[0]);
    }

    /**
     * @param  array{int, int, int}  $rgb
     * @param  float  $spacing  extra pixels between characters, for the kicker
     * @param  float|null  $opacity  1.0 is solid
     */
    private function text(
        GdImage $canvas,
        string $text,
        int $x,
        int $y,
        int $size,
        array $rgb,
        string $font,
        ?float $opacity = null,
        float $spacing = 0.0,
    ): void {
        $colour = $opacity === null || $opacity >= 1.0
            ? $this->colour($canvas, $rgb)
            : imagecolorallocatealpha($canvas, $rgb[0], $rgb[1], $rgb[2], (int) round((1 - $opacity) * 127));

        if ($spacing <= 0.0) {
            imagettftext($canvas, $size, 0, $x, $y, $colour, $font, $text);

            return;
        }

        // GD has no letter-spacing, and the kicker needs it to read as a label
        // rather than a small heading. One glyph at a time, then.
        $cursor = $x;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $glyph) {
            imagettftext($canvas, $size, 0, (int) round($cursor), $y, $colour, $font, $glyph);
            $cursor += $this->width($glyph, $font, $size) + $spacing;
        }
    }

    private function roundedRectangle(GdImage $canvas, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
    {
        imagefilledrectangle($canvas, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour);
        imagefilledrectangle($canvas, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour);

        $d = $radius * 2;
        imagefilledellipse($canvas, $x1 + $radius, $y1 + $radius, $d, $d, $colour);
        imagefilledellipse($canvas, $x2 - $radius, $y1 + $radius, $d, $d, $colour);
        imagefilledellipse($canvas, $x1 + $radius, $y2 - $radius, $d, $d, $colour);
        imagefilledellipse($canvas, $x2 - $radius, $y2 - $radius, $d, $d, $colour);
    }

    /** @param array{int, int, int} $rgb */
    private function colour(GdImage $canvas, array $rgb): int
    {
        return (int) imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Fail loudly when the build cannot draw type.
     *
     * GD compiled without FreeType does not error on `imagettftext` in any way a
     * caller notices — it emits a warning, draws nothing, and hands back a
     * perfectly valid PNG. The card would be a teal rectangle with a logo on it,
     * a 200 response, and **cached for a week by every platform that fetched
     * it** before anyone saw one.
     *
     * A 500 on an image endpoint is the better failure: scrapers retry, and the
     * error reaches the log on the first request rather than the first
     * screenshot. The runtime image installs gd through
     * `install-php-extensions`, which enables FreeType, so this is a guard
     * against a future change to that line rather than a known problem.
     */
    private function assertFontsUsable(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        if (@imagettfbbox(12, 0, self::bold(), 'Ag') === false) {
            throw new RuntimeException(
                'Cannot render social cards: GD has no usable FreeType support, or '
                .self::bold().' is missing. See docs/features/social-cards.md.'
            );
        }

        $checked = true;
    }

    private static function regular(): string
    {
        return resource_path('fonts/Inter-Regular.ttf');
    }

    private static function semibold(): string
    {
        return resource_path('fonts/Inter-SemiBold.ttf');
    }

    private static function bold(): string
    {
        return resource_path('fonts/Inter-Bold.ttf');
    }
}
