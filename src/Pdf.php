<?php
declare(strict_types=1);

namespace Festkasse;

/**
 * Minimal dependency-free PDF writer — no Composer/vendor, single file, same "zero dependencies,
 * FTP deploy" model as the rest of the app. Line-based: text lines, lines split into columns
 * (addRow), a right-aligned amount (addSplitLine), hairline rules and a coloured banner —
 * auto-paginates onto additional A4 pages. Not a general-purpose layout engine.
 *
 * Look (Verwaltung → Einstellungen → PDF-Gestaltung): an embedded TrueType font from /fonts
 * (Google Fonts) or the built-in Courier, an accent colour for title and banner, and a logo
 * (JPEG) at the top right of the first page.
 */
final class Pdf
{
    private const WIDTH = 595.28;  // A4 in points
    private const HEIGHT = 841.89;
    private const MARGIN = 40.0;
    private const BODY_SIZE = 9.0;
    private const BODY_LEADING = 12.0;
    private const CONTENT_WIDTH = self::WIDTH - 2 * self::MARGIN;

    public const GREEN = [0.10, 0.55, 0.25];
    public const RED = [0.75, 0.15, 0.15];
    public const WHITE = [1.0, 1.0, 1.0];
    public const BLACK = [0.0, 0.0, 0.0];
    public const DEFAULT_ACCENT = '#1A2659';

    /** @var array<int, array<int, array<string, mixed>>> one array of draw-ops per page */
    private array $pages = [];
    /** @var array<int, array<string, mixed>> */
    private array $currentOps = [];
    private float $y;

    private ?TtfFont $regular = null;
    private ?TtfFont $bold = null;
    /** @var array{0:float,1:float,2:float} */
    private array $accent;
    /** @var ?array{data:string,w:int,h:int} */
    private ?array $logo = null;

    /**
     * @param array{font?:string, color?:string, logo?:?string} $theme
     *        font: key of TtfFont::AVAILABLE, color: '#RRGGBB', logo: JPEG bytes
     */
    public function __construct(string $title, array $theme = [])
    {
        $font = (string) ($theme['font'] ?? '');
        if ($font !== '') {
            try {
                $this->regular = TtfFont::load($font, false);
                $this->bold = TtfFont::load($font, true);
            } catch (\Throwable $e) {
                error_log('[Festkasse PDF] Schrift ' . $font . ' nicht lesbar, nehme Courier: ' . $e->getMessage());
            }
            if ($this->regular === null || $this->bold === null) {
                $this->regular = $this->bold = null;
            }
        }
        $this->accent = self::hexToRgb((string) ($theme['color'] ?? '')) ?? self::hexToRgb(self::DEFAULT_ACCENT);
        if (!empty($theme['logo'])) {
            $info = @getimagesizefromstring($theme['logo']);
            if ($info !== false && ($info[2] ?? null) === IMAGETYPE_JPEG) {
                $this->logo = ['data' => $theme['logo'], 'w' => (int) $info[0], 'h' => (int) $info[1], 'gray' => ($info['channels'] ?? 3) === 1];
            }
        }

        $this->startPage();
        $titleColor = self::luminance($this->accent) > 0.6 ? self::BLACK : $this->accent;
        if ($this->logo !== null) {
            // Logo at the top right, at most 160 × 55 pt; the title sits next to it on the left.
            $scale = min(160 / $this->logo['w'], 55 / $this->logo['h']);
            $w = $this->logo['w'] * $scale;
            $h = $this->logo['h'] * $scale;
            $top = self::HEIGHT - self::MARGIN + 12;
            $this->currentOps[] = ['type' => 'image', 'x' => self::WIDTH - self::MARGIN - $w, 'y' => $top - $h, 'w' => $w, 'h' => $h];
            $this->y = $top - min($h, 30) + 2;
            $this->addLine($this->fit($title, true, 14.0, self::CONTENT_WIDTH - $w - 16), true, 14.0, $titleColor);
            $this->y = min($this->y, $top - $h - 8);
        } else {
            $this->addLine($title, true, 14.0, $titleColor);
        }
        $this->addSpacer(8.0);
    }

    /** The banner colour configured in the settings (for callers that want to reuse it). */
    public function accent(): array
    {
        return $this->accent;
    }

    private function startPage(): void
    {
        if ($this->currentOps !== []) {
            $this->pages[] = $this->currentOps;
        }
        $this->currentOps = [];
        $this->y = self::HEIGHT - self::MARGIN;
    }

    private function ensureSpace(float $leading): void
    {
        if ($this->y - $leading < self::MARGIN) {
            $this->startPage();
        }
    }

    /** @param ?array{0:float,1:float,2:float} $color null = black */
    public function addLine(string $text, bool $bold = false, float $size = self::BODY_SIZE, ?array $color = null): void
    {
        $leading = max(self::BODY_LEADING, $size + 3.0);
        $this->ensureSpace($leading);
        $this->text(self::MARGIN, $this->fit($text, $bold, $size, self::CONTENT_WIDTH), $bold, $size, $color);
        $this->y -= $leading;
    }

    public function addSpacer(float $height = self::BODY_LEADING): void
    {
        $this->y -= $height;
    }

    /**
     * One line with a right-aligned trailing segment (usually an amount) that can be coloured on
     * its own. The left text is shortened with "…" if it would run into the right one.
     * @param ?array{0:float,1:float,2:float} $leftColor null = black
     * @param ?array{0:float,1:float,2:float} $rightColor null = black
     */
    public function addSplitLine(
        string $left,
        string $right,
        bool $bold = false,
        ?array $leftColor = null,
        ?array $rightColor = null,
        float $size = self::BODY_SIZE
    ): void {
        $leading = max(self::BODY_LEADING, $size + 3.0);
        $this->ensureSpace($leading);
        $rightWidth = $this->textWidth($right, $bold, $size);
        $this->text(self::MARGIN, $this->fit($left, $bold, $size, self::CONTENT_WIDTH - $rightWidth - 12), $bold, $size, $leftColor);
        $this->text(self::WIDTH - self::MARGIN - $rightWidth, $right, $bold, $size, $rightColor);
        $this->y -= $leading;
    }

    /**
     * One table row. Each cell: [text, width as share of the content width, 'L'|'R', ?colour].
     * Cells are laid out left to right; text that doesn't fit its cell is shortened with "…".
     * @param array<int, array{0:string, 1:float, 2?:string, 3?:?array}> $cells
     * @param ?array{0:float,1:float,2:float} $color default colour for all cells
     */
    public function addRow(array $cells, bool $bold = false, ?array $color = null, float $indent = 0.0, float $size = self::BODY_SIZE): void
    {
        $leading = max(self::BODY_LEADING, $size + 3.0);
        $this->ensureSpace($leading);
        $x = self::MARGIN + $indent;
        $available = self::CONTENT_WIDTH - $indent;
        foreach ($cells as $cell) {
            $w = $available * $cell[1];
            $align = $cell[2] ?? 'L';
            // a little air between columns
            $text = $this->fit($cell[0], $bold, $size, $w - ($align === 'R' ? 0 : 6));
            $tx = $align === 'R' ? $x + $w - $this->textWidth($text, $bold, $size) : $x;
            $this->text($tx, $text, $bold, $size, $cell[3] ?? $color);
            $x += $w;
        }
        $this->y -= $leading;
    }

    /** @param string[] $lines */
    public function addLines(array $lines, bool $bold = false, float $size = self::BODY_SIZE): void
    {
        foreach ($lines as $line) {
            $this->addLine($line, $bold, $size);
        }
    }

    /**
     * A hairline row separator spanning the full content width. $gap is the whitespace kept
     * clear of the glyphs above and below (corrected for the descender below the line above
     * and the cap height of the line below, so the rule sits visually centred).
     * @param array{0:float,1:float,2:float} $color
     */
    public function addRule(array $color = [0.85, 0.85, 0.85], float $thickness = 0.75, float $gap = 4.0): void
    {
        $descender = 0.2 * self::BODY_SIZE;
        $capHeight = 0.7 * self::BODY_SIZE;
        $spacingBefore = $gap - (self::BODY_LEADING - $descender);
        $spacingAfter = $gap + $capHeight;

        $this->y -= $spacingBefore;
        if ($this->y - $thickness < self::MARGIN) {
            $this->startPage();
        }
        $this->currentOps[] = ['type' => 'rect', 'x' => self::MARGIN, 'y' => $this->y - $thickness, 'w' => self::CONTENT_WIDTH, 'h' => $thickness, 'color' => $color];
        $this->y -= $thickness + $spacingAfter;
    }

    /**
     * A full-width coloured banner (e.g. a Z-Bericht's Endstand) — never split across a page
     * break. Without a colour it uses the accent colour from the settings; the text turns black
     * on light colours so it stays readable.
     * @param array<int, array{text: string, bold?: bool, size?: float}> $lines
     * @param ?array{0: float, 1: float, 2: float} $bgColor
     */
    public function addBanner(array $lines, ?array $bgColor = null, ?array $textColor = null): void
    {
        $bgColor ??= $this->accent;
        $textColor ??= self::luminance($bgColor) > 0.6 ? self::BLACK : self::WHITE;
        $padY = 10.0;
        $padX = 14.0;
        $leading = self::BODY_LEADING + 4.0;
        $boxHeight = count($lines) * $leading + 2 * $padY;
        $this->ensureSpace($boxHeight);

        $boxTop = $this->y + 8.0;
        $boxBottom = $boxTop - $boxHeight;
        $this->currentOps[] = ['type' => 'rect', 'x' => self::MARGIN - $padX, 'y' => $boxBottom, 'w' => self::WIDTH - 2 * (self::MARGIN - $padX), 'h' => $boxHeight, 'color' => $bgColor];

        $this->y -= $padY - 2.0;
        foreach ($lines as $l) {
            $size = $l['size'] ?? (self::BODY_SIZE + 1.0);
            $bold = $l['bold'] ?? false;
            $this->text(self::MARGIN, $this->fit($l['text'], $bold, $size, self::CONTENT_WIDTH), $bold, $size, $textColor);
            $this->y -= $leading;
        }
        $this->y = $boxBottom - 12.0;
    }

    private function text(float $x, string $text, bool $bold, float $size, ?array $color): void
    {
        $this->currentOps[] = ['type' => 'text', 'x' => $x, 'y' => $this->y, 'text' => $text, 'bold' => $bold, 'size' => $size, 'color' => $color ?? self::BLACK];
    }

    /** Width of a UTF-8 string in points, in the configured font. */
    public function textWidth(string $text, bool $bold = false, float $size = self::BODY_SIZE): float
    {
        $font = $bold ? $this->bold : $this->regular;
        if ($font === null) {
            return strlen(self::toCp1252($text)) * $size * 0.6; // Courier: every glyph 600/1000 em
        }
        return $font->width(self::toCp1252($text), $size);
    }

    /** Shortens text with "…" until it fits into $maxWidth points. */
    private function fit(string $text, bool $bold, float $size, float $maxWidth): string
    {
        if ($maxWidth <= 0 || $this->textWidth($text, $bold, $size) <= $maxWidth) {
            return $text;
        }
        $len = mb_strlen($text, 'UTF-8');
        while ($len > 0 && $this->textWidth(mb_substr($text, 0, $len, 'UTF-8') . '…', $bold, $size) > $maxWidth) {
            $len--;
        }
        return rtrim(mb_substr($text, 0, $len, 'UTF-8')) . '…';
    }

    public function output(): string
    {
        if ($this->currentOps !== [] || $this->pages === []) {
            $this->pages[] = $this->currentOps;
        }

        /** @var array<int, string|array{stream: string, dict?: string}> $objects */
        $objects = [];
        $n = 0;
        $alloc = static function () use (&$n): int {
            return ++$n;
        };
        $catalog = $alloc();
        $pagesObj = $alloc();
        $f1 = $alloc();
        $f2 = $alloc();
        $objects[$catalog] = '<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>';

        foreach ([[$f1, $this->regular, 'Courier'], [$f2, $this->bold, 'Courier-Bold']] as [$num, $font, $builtin]) {
            if ($font === null) {
                $objects[$num] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $builtin . ' /Encoding /WinAnsiEncoding >>';
                continue;
            }
            $descNum = $alloc();
            $fileNum = $alloc();
            $objects[$num] = '<< /Type /Font /Subtype /TrueType /BaseFont /' . $font->postScriptName
                . ' /FirstChar 32 /LastChar 255 /Widths [' . implode(' ', $font->widths) . ']'
                . ' /Encoding /WinAnsiEncoding /FontDescriptor ' . $descNum . ' 0 R >>';
            $objects[$descNum] = '<< /Type /FontDescriptor /FontName /' . $font->postScriptName . ' /Flags 32'
                . ' /FontBBox [' . implode(' ', $font->bbox) . '] /ItalicAngle 0 /Ascent ' . $font->ascent
                . ' /Descent ' . $font->descent . ' /CapHeight ' . $font->capHeight . ' /StemV ' . ($num === $f2 ? 140 : 80)
                . ' /FontFile2 ' . $fileNum . ' 0 R >>';
            $objects[$fileNum] = ['stream' => gzcompress($font->data, 9), 'dict' => '/Filter /FlateDecode /Length1 ' . strlen($font->data)];
        }

        $imageRes = '';
        if ($this->logo !== null) {
            $imgNum = $alloc();
            $objects[$imgNum] = ['stream' => $this->logo['data'], 'dict' => '/Type /XObject /Subtype /Image /Width ' . $this->logo['w']
                . ' /Height ' . $this->logo['h'] . ' /ColorSpace /' . ($this->logo['gray'] ? 'DeviceGray' : 'DeviceRGB')
                . ' /BitsPerComponent 8 /Filter /DCTDecode'];
            $imageRes = ' /XObject << /Im1 ' . $imgNum . ' 0 R >>';
        }

        $kids = [];
        foreach ($this->pages as $ops) {
            $pageNum = $alloc();
            $contentNum = $alloc();
            $kids[] = $pageNum . ' 0 R';
            $objects[$pageNum] = '<< /Type /Page /Parent ' . $pagesObj . ' 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] '
                . '/Resources << /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >>' . $imageRes . ' >> /Contents ' . $contentNum . ' 0 R >>';

            $stream = '';
            foreach ($ops as $op) {
                if ($op['type'] === 'rect') {
                    $stream .= sprintf("%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n0 0 0 rg\n", ...[...$op['color'], $op['x'], $op['y'], $op['w'], $op['h']]);
                    continue;
                }
                if ($op['type'] === 'image') {
                    $stream .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $op['w'], $op['h'], $op['x'], $op['y']);
                    continue;
                }
                $stream .= sprintf(
                    "%.3F %.3F %.3F rg\nBT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
                    $op['color'][0],
                    $op['color'][1],
                    $op['color'][2],
                    $op['bold'] ? 'F2' : 'F1',
                    $op['size'],
                    $op['x'],
                    $op['y'],
                    str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::toCp1252($op['text']))
                );
            }
            $objects[$contentNum] = ['stream' => gzcompress($stream, 6), 'dict' => '/Filter /FlateDecode'];
        }
        $objects[$pagesObj] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        return $this->assemble($objects);
    }

    /**
     * Both the built-in fonts and the embedded TrueType fonts use WinAnsiEncoding (single byte) —
     * convert from the app's UTF-8 to CP1252 so umlauts, € and – render correctly.
     */
    private static function toCp1252(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }
        return $converted;
    }

    /** @return ?array{0:float,1:float,2:float} */
    public static function hexToRgb(string $hex): ?array
    {
        if (!preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return null;
        }
        return array_map(static fn (string $c): float => hexdec($c) / 255, str_split($m[1], 2));
    }

    private static function luminance(array $rgb): float
    {
        return 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2];
    }

    /** @param array<int, string|array{stream: string, dict?: string}> $objects */
    private function assemble(array $objects): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $maxNum = max(array_keys($objects));
        $offsets = [];

        for ($n = 1; $n <= $maxNum; $n++) {
            $offsets[$n] = strlen($out);
            $body = $objects[$n];
            if (is_array($body)) {
                $data = $body['stream'];
                $dict = isset($body['dict']) ? ' ' . $body['dict'] : '';
                $out .= "{$n} 0 obj\n<< /Length " . strlen($data) . "{$dict} >>\nstream\n{$data}\nendstream\nendobj\n";
            } else {
                $out .= "{$n} 0 obj\n{$body}\nendobj\n";
            }
        }

        $xrefStart = strlen($out);
        $count = $maxNum + 1;
        $out .= "xref\n0 {$count}\n";
        $out .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxNum; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $out;
    }
}
