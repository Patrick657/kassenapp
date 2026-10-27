<?php
declare(strict_types=1);

namespace Festkasse;

/**
 * Reads the few things the PDF writer needs from a TrueType file (.ttf, e.g. from Google Fonts):
 * glyph widths for the WinAnsi (CP1252) characters, and the metrics for the font descriptor.
 * The whole file is then embedded as-is (FontFile2) — no subsetting, no dependencies.
 */
final class TtfFont
{
    /** Bundled fonts in /fonts (Google Fonts, open licences). Key => label. '' = Courier (built in). */
    public const AVAILABLE = [
        '' => 'Courier (Schreibmaschine)',
        'Figtree' => 'Figtree',
        'Inter' => 'Inter',
        'Lato' => 'Lato',
        'Montserrat' => 'Montserrat',
        'OpenSans' => 'Open Sans',
        'Roboto' => 'Roboto',
        'SourceSans3' => 'Source Sans 3',
    ];

    public readonly string $data;
    public readonly string $postScriptName;
    /** @var int[] widths (1/1000 em) for codes 32..255 */
    public readonly array $widths;
    public readonly array $bbox;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;

    /** @var array<string, self> */
    private static array $cache = [];

    public static function load(string $key, bool $bold): ?self
    {
        if (!isset(self::AVAILABLE[$key]) || $key === '') {
            return null;
        }
        $file = dirname(__DIR__) . '/fonts/' . $key . '-' . ($bold ? 'Bold' : 'Regular') . '.ttf';
        if (!is_file($file)) {
            return null;
        }
        return self::$cache[$file] ??= new self((string) file_get_contents($file), $key . '-' . ($bold ? 'Bold' : 'Regular'));
    }

    private function __construct(string $data, string $name)
    {
        $this->data = $data;
        $this->postScriptName = $name;
        $tables = [];
        $num = $this->u16(4);
        for ($i = 0; $i < $num; $i++) {
            $rec = 12 + 16 * $i;
            $tables[substr($data, $rec, 4)] = $this->u32($rec + 8);
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $t) {
            if (!isset($tables[$t])) {
                throw new \RuntimeException("Schriftdatei unvollständig ($t fehlt)");
            }
        }
        $head = $tables['head'];
        $upem = $this->u16($head + 18) ?: 1000;
        $scale = static fn (int $v): int => (int) round($v * 1000 / $upem);
        $this->bbox = array_map($scale, [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)]);

        $hhea = $tables['hhea'];
        $this->ascent = $scale($this->s16($hhea + 4));
        $this->descent = $scale($this->s16($hhea + 6));
        $numMetrics = $this->u16($hhea + 34);

        $capHeight = (int) round($this->ascent * 0.7);
        if (isset($tables['OS/2']) && $this->u16($tables['OS/2']) >= 2) {
            $capHeight = $scale($this->s16($tables['OS/2'] + 88));
        }
        $this->capHeight = $capHeight;

        $cmap = $this->unicodeCmap($tables['cmap']);
        $hmtx = $tables['hmtx'];
        $advance = function (int $glyph) use ($hmtx, $numMetrics): int {
            $idx = min($glyph, $numMetrics - 1);
            return $this->u16($hmtx + 4 * $idx);
        };
        $widths = [];
        for ($code = 32; $code <= 255; $code++) {
            $utf8 = @iconv('CP1252', 'UTF-8', chr($code));
            $glyph = ($utf8 !== false && $utf8 !== '') ? ($cmap[mb_ord($utf8, 'UTF-8')] ?? 0) : 0;
            $widths[] = $scale($advance($glyph));
        }
        $this->widths = $widths;
    }

    /** Width of a CP1252 string in points. */
    public function width(string $cp1252, float $size): float
    {
        $sum = 0;
        $len = strlen($cp1252);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($cp1252[$i]);
            $sum += $code >= 32 ? $this->widths[$code - 32] : 0;
        }
        return $sum * $size / 1000;
    }

    /** @return array<int, int> unicode code point => glyph id (cmap format 4, Windows Unicode BMP) */
    private function unicodeCmap(int $cmap): array
    {
        $count = $this->u16($cmap + 2);
        $sub = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $cmap + 4 + 8 * $i;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $off = $cmap + $this->u32($rec + 4);
            if ($this->u16($off) === 4 && (($platform === 3 && $encoding === 1) || $platform === 0)) {
                $sub = $off;
                if ($platform === 3) {
                    break;
                }
            }
        }
        if ($sub === null) {
            throw new \RuntimeException('Schriftdatei ohne Unicode-Zeichentabelle');
        }
        $segX2 = $this->u16($sub + 6);
        $ends = $sub + 14;
        $starts = $ends + $segX2 + 2;
        $deltas = $starts + $segX2;
        $ranges = $deltas + $segX2;
        $map = [];
        for ($s = 0; $s < $segX2; $s += 2) {
            $end = $this->u16($ends + $s);
            $start = $this->u16($starts + $s);
            $delta = $this->u16($deltas + $s);
            $rangeOff = $this->u16($ranges + $s);
            // Only the code points CP1252 can reach matter (Latin-1 plus a few like € – … ‚ „).
            for ($c = $start; $c <= $end && $c <= 0x2122; $c++) {
                if ($c === 0xFFFF) {
                    break;
                }
                if ($rangeOff === 0) {
                    $g = ($c + $delta) & 0xFFFF;
                } else {
                    $g = $this->u16($ranges + $s + $rangeOff + 2 * ($c - $start));
                    if ($g !== 0) {
                        $g = ($g + $delta) & 0xFFFF;
                    }
                }
                $map[$c] = $g;
            }
        }
        return $map;
    }

    private function u16(int $o): int
    {
        return unpack('n', $this->data, $o)[1];
    }

    private function s16(int $o): int
    {
        $v = $this->u16($o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        return unpack('N', $this->data, $o)[1];
    }
}
