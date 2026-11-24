<?php

namespace App\Services;

use App\Enums\BrandPaletteEnum;
use Illuminate\Container\Attributes\Singleton;

/**
 * The site's brand colour, and the shades the stylesheet is drawn from.
 *
 * Tailwind v4 compiles `bg-brand-400` to `var(--color-brand-400)`, not to a hex,
 * so the whole interface can be recoloured without a rebuild: the base layout
 * prints the variables this service works out, after the compiled stylesheet,
 * and every brand-* utility follows — lime-* and Flux's accent with it, since
 * app.css points both at the brand scale. One build serves every install; the
 * colour is data, like the site name.
 *
 * An administrator picks one hex. The other ten shades are derived in OKLCH,
 * which keeps a ramp even to the eye where stepping RGB would not: the chosen
 * hex lands exactly on ANCHOR, lighter shades climb towards white and darker
 * ones fall towards black, with the chroma eased off at both ends so a 50 reads
 * as a tint rather than a pastel of the full colour.
 */
#[Singleton]
class BrandColorService
{
    public const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /**
     * The shade the chosen hex is placed on. The kit's accent is brand-400, so
     * that is where a picked colour lands — the buttons come out the colour
     * somebody chose, not a shade next to it.
     */
    public const ANCHOR = 400;

    /**
     * An even OKLCH lightness curve across the shades, close to Tailwind's own.
     * Never used as is: scale() bends it so the anchor sits on the chosen colour.
     */
    private const LIGHTNESS = [
        50 => 0.975, 100 => 0.945, 200 => 0.89, 300 => 0.82, 400 => 0.745, 500 => 0.665,
        600 => 0.575, 700 => 0.49, 800 => 0.415, 900 => 0.345, 950 => 0.26,
    ];

    private array $scales = [];

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLIC

    /**
     * The hex in force. A preset is read from the enum rather than from the
     * stored copy, so a preset tuned in code reaches every install using it; only
     * Custom reads the stored hex. Anything unreadable falls back to the compiled
     * teal rather than painting the site with nothing.
     *
     * @return array{palette: BrandPaletteEnum, primary: string}
     */
    public function palette(): array
    {
        $stored = (array) kSiteConfig('brand', default: []);
        $palette = BrandPaletteEnum::tryFrom((string) data_get($stored, 'palette')) ?? BrandPaletteEnum::TEAL;

        return [
            'palette' => $palette,
            'primary' => $palette->isCustom()
                ? ($this->normalize(data_get($stored, 'primary')) ?? BrandPaletteEnum::TEAL->primary())
                : $palette->primary(),
        ];
    }

    /**
     * One shade of the brand as a hex, for the places a CSS variable cannot
     * reach: email, which most clients render without custom properties.
     */
    public function color(int $shade = self::ANCHOR): string
    {
        $hex = $this->palette()['primary'];

        return $this->scale($hex)[$shade] ?? $hex;
    }

    /**
     * The text colour a button filled with this hex should use.
     *
     * White where it reads at 3:1 or better — the large-text bar, which a
     * button label meets. Below that (the compiled teal is one) the deep end of
     * the same colour is used instead, the way app.css pairs brand-400 with
     * brand-900.
     */
    public function foreground(string $hex): string
    {
        return $this->contrast($hex, '#ffffff') >= 3
            ? '#ffffff'
            : $this->scale($hex)[900];
    }

    /**
     * Eleven shades for one colour, keyed 50 to 950, with the colour itself on
     * the anchor.
     *
     * @return array<int, string>
     */
    public function scale(string $hex, int $anchor = self::ANCHOR): array
    {
        $hex = $this->normalize($hex) ?? BrandPaletteEnum::TEAL->primary();
        $memo = "{$hex}:{$anchor}";

        if (isset($this->scales[$memo])) {
            return $this->scales[$memo];
        }

        [$baseL, $baseC, $hue] = $this->toOklch($hex);
        $curve = self::LIGHTNESS[$anchor];
        $white = self::LIGHTNESS[50];

        $scale = [];
        foreach (self::SHADES as $shade) {
            if ($shade === $anchor) {
                $scale[$shade] = $hex;

                continue;
            }

            // Lighter shades cover the same share of the way to white that the
            // even curve does from the anchor; darker ones keep the curve's ratio
            // to the anchor.
            $lightness = $shade < $anchor
                ? $baseL + ($white - $baseL) * (self::LIGHTNESS[$shade] - $curve) / max($white - $curve, 0.01)
                : $baseL * self::LIGHTNESS[$shade] / $curve;

            // Chroma eases off towards both ends, so a 50 is a tint rather than a
            // pastel and a 950 a deep shade, not mud. A dark anchor holds little
            // chroma only because it is dark, so its lighter shades may take as
            // much more as their lightness has room for — otherwise the ramp
            // above a deep colour fades to grey.
            $chroma = $shade < $anchor
                ? $baseC * min(1, ((1 - $lightness) / 0.25) ** 0.8) * max(1, $this->chromaRoom($lightness) / max($this->chromaRoom($baseL), 0.05))
                : $baseC * ($lightness / max($baseL, 0.01)) ** 0.9;

            $scale[$shade] = $this->fromOklch($lightness, min($chroma, 0.37), $hue);
        }

        return $this->scales[$memo] = $scale;
    }

    /**
     * The <style> body the base layout prints after the compiled stylesheet.
     *
     * Empty for the teal app.css was compiled with: that ramp is Tailwind's own,
     * tuned by hand, and printing a generated one over it would shift every
     * shade but the anchor for no reason.
     *
     * Unlayered, so it outranks the @layer theme rules in app.css whatever their
     * specificity. That is also why the dark button text is restated here: the
     * stylesheet's `.dark` value lives in a layer and would otherwise lose to the
     * light one printed below.
     *
     * Not memoised: a singleton outlives a save, and the scales underneath are
     * memoised by hex already, so asking twice costs almost nothing.
     */
    public function styles(): string
    {
        $primary = $this->palette()['primary'];

        if ($primary === BrandPaletteEnum::TEAL->primary()) {
            return '';
        }

        $variables = [];
        foreach ($this->scale($primary) as $shade => $hex) {
            $variables[] = "--color-brand-{$shade}:{$hex}";
        }

        $foreground = $this->foreground($primary);
        $variables[] = "--color-accent-foreground:{$foreground}";

        // Dark text on the accent goes one shade deeper in the dark theme, as
        // app.css does; white stays white.
        $darkForeground = $foreground === '#ffffff' ? $foreground : 'var(--color-brand-950)';

        return ':root{'.implode(';', $variables).'}'
            .".dark{--color-accent-foreground:{$darkForeground}}";
    }

    /**
     * "#abc", "abc", "#AABBCC" and "aabbcc" all become "#aabbcc"; anything else is null.
     */
    public function normalize(mixed $hex): ?string
    {
        $hex = strtolower(ltrim(trim((string) $hex), '#'));

        if (preg_match('/^[0-9a-f]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $hex) ? "#{$hex}" : null;
    }

    /**
     * WCAG contrast ratio between two hexes, 1 to 21.
     */
    public function contrast(string $first, string $second): float
    {
        $a = $this->luminance($first);
        $b = $this->luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * A rough measure of how much chroma sRGB can hold at a lightness: none at
     * black or white, most in the middle. Only ever used as a ratio.
     */
    private function chromaRoom(float $lightness): float
    {
        return sin(M_PI * max(0.0, min(1.0, $lightness)));
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn ($channel) => $this->toLinear($channel), $this->toRgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{0: float, 1: float, 2: float} sRGB channels, 0 to 1
     */
    private function toRgb(string $hex): array
    {
        $hex = ltrim($this->normalize($hex) ?? '#000000', '#');

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    private function toLinear(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private function fromLinear(float $channel): float
    {
        return $channel <= 0.0031308 ? $channel * 12.92 : 1.055 * ($channel ** (1 / 2.4)) - 0.055;
    }

    /**
     * @return array{0: float, 1: float, 2: float} lightness, chroma, hue in radians
     */
    private function toOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($channel) => $this->toLinear($channel), $this->toRgb($hex));

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$lightness, sqrt($a * $a + $bb * $bb), atan2($bb, $a)];
    }

    /**
     * Back to a hex, giving up chroma until the colour fits inside sRGB. A
     * clamped channel would shift the hue instead; less saturation keeps it.
     */
    private function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        $rgb = $this->oklchToLinear($lightness, $chroma, $hue);

        if (! $this->inGamut($rgb)) {
            $low = 0.0;
            $high = $chroma;
            for ($step = 0; $step < 20; $step++) {
                $middle = ($low + $high) / 2;
                $this->inGamut($this->oklchToLinear($lightness, $middle, $hue)) ? $low = $middle : $high = $middle;
            }
            $rgb = $this->oklchToLinear($lightness, $low, $hue);
        }

        return '#'.implode('', array_map(
            fn ($channel) => str_pad(dechex((int) round(max(0, min(1, $this->fromLinear(max(0, $channel)))) * 255)), 2, '0', STR_PAD_LEFT),
            $rgb,
        ));
    }

    /**
     * @return array{0: float, 1: float, 2: float} linear sRGB
     */
    private function oklchToLinear(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private function inGamut(array $rgb): bool
    {
        foreach ($rgb as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }
}
