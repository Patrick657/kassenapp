<?php
declare(strict_types=1);

namespace Festkasse;

/**
 * Minimal dependency-free PDF writer — no Composer/vendor, single file, same "zero dependencies,
 * FTP deploy" model as the rest of the app (mirrors why the CSV export uses plain fputcsv instead
 * of a spreadsheet library). Line-based only: one monospace (Courier) line at a time, normal or
 * bold, optionally coloured, plus filled rectangles for a coloured summary banner — auto-paginates
 * onto additional A4 pages. Enough for the ledger-style reports this app emails (Journal,
 * Auswertungen, Z-Bericht), not a general-purpose layout engine.
 */
final class Pdf
{
    private const WIDTH = 595.28;  // A4 in points
    private const HEIGHT = 841.89;
    private const MARGIN = 40.0;
    private const BODY_SIZE = 9.0;
    private const BODY_LEADING = 12.0;

    public const GREEN = [0.10, 0.55, 0.25];
    public const RED = [0.75, 0.15, 0.15];
    public const WHITE = [1.0, 1.0, 1.0];
    public const BLACK = [0.0, 0.0, 0.0];
    public const GRAY = [0.45, 0.45, 0.45];

    /** Roughly how many Courier characters fit one line at BODY_SIZE within the margins. */
    public const CHARS_PER_LINE = 92;

    /** @var array<int, array<int, array<string, mixed>>> one array of draw-ops per page */
    private array $pages = [];
    /** @var array<int, array<string, mixed>> */
    private array $currentOps = [];
    private float $y;

    public function __construct(string $title)
    {
        $this->startPage();
        $this->addLine($title, true, 14.0);
        $this->addSpacer(8.0);
    }

    private function startPage(): void
    {
        if ($this->currentOps !== []) {
            $this->pages[] = $this->currentOps;
        }
        $this->currentOps = [];
        $this->y = self::HEIGHT - self::MARGIN;
    }

    /** @param ?array{0:float,1:float,2:float} $color null = black */
    public function addLine(string $text, bool $bold = false, float $size = self::BODY_SIZE, ?array $color = null): void
    {
        $leading = max(self::BODY_LEADING, $size + 3.0);
        if ($this->y - $leading < self::MARGIN) {
            $this->startPage();
        }
        $this->currentOps[] = ['type' => 'text', 'y' => $this->y, 'text' => $text, 'bold' => $bold, 'size' => $size, 'color' => $color ?? self::BLACK];
        $this->y -= $leading;
    }

    public function addSpacer(float $height = self::BODY_LEADING): void
    {
        $this->y -= $height;
    }

    /** @param string[] $lines */
    public function addLines(array $lines, bool $bold = false, float $size = self::BODY_SIZE): void
    {
        foreach ($lines as $line) {
            $this->addLine($line, $bold, $size);
        }
    }

    /**
     * A full-width coloured banner (e.g. a Z-Bericht's Endstand) — never split across a page
     * break: if it wouldn't fit in the space left on the current page, a fresh page starts first.
     * @param array<int, array{text: string, bold?: bool, size?: float}> $lines
     * @param array{0: float, 1: float, 2: float} $bgColor
     * @param array{0: float, 1: float, 2: float} $textColor
     */
    public function addBanner(array $lines, array $bgColor, array $textColor = self::WHITE): void
    {
        $padY = 10.0;
        $padX = 14.0;
        $leading = self::BODY_LEADING + 4.0;
        $blockHeight = count($lines) * $leading;
        $boxHeight = $blockHeight + 2 * $padY;

        if ($this->y - $boxHeight < self::MARGIN) {
            $this->startPage();
        }

        // Box top sits a little above the first line's baseline (room for the glyphs' ascenders).
        $boxTop = $this->y + 8.0;
        $boxBottom = $boxTop - $boxHeight;
        $rectX = self::MARGIN - $padX;
        $rectW = self::WIDTH - 2 * (self::MARGIN - $padX);
        $this->currentOps[] = ['type' => 'rect', 'x' => $rectX, 'y' => $boxBottom, 'w' => $rectW, 'h' => $boxHeight, 'color' => $bgColor];

        $this->y -= $padY - 2.0;
        foreach ($lines as $l) {
            $this->currentOps[] = [
                'type' => 'text',
                'y' => $this->y,
                'text' => $l['text'],
                'bold' => $l['bold'] ?? false,
                'size' => $l['size'] ?? (self::BODY_SIZE + 1.0),
                'color' => $textColor,
            ];
            $this->y -= $leading;
        }
        // Fixed clearance below the box's actual bottom edge (not a running subtraction from
        // wherever the loop left off) — the next line's text still reaches a font-size-ish
        // distance *above* its own baseline (ascenders), so "just past the edge" isn't enough
        // room on its own; this must never end up between $boxBottom and $boxBottom minus that.
        $this->y = $boxBottom - 12.0;
    }

    public function output(): string
    {
        if ($this->currentOps !== [] || $this->pages === []) {
            $this->pages[] = $this->currentOps;
        }

        // Fixed low object numbers for the catalog/pages/fonts, then one page + one content
        // stream object per page, strictly sequential — the xref table below relies on every
        // number from 1..max being present with no gaps.
        $pageObjNums = [];
        $contentObjNums = [];
        $next = 5;
        foreach ($this->pages as $i => $ops) {
            $pageObjNums[$i] = $next++;
            $contentObjNums[$i] = $next++;
        }

        /** @var array<int, string|array{stream: string}> $objects */
        $objects = [];
        $kids = implode(' ', array_map(static fn (int $n): string => "{$n} 0 R", $pageObjNums));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($this->pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $i => $ops) {
            $pageNum = $pageObjNums[$i];
            $contentNum = $contentObjNums[$i];
            $objects[$pageNum] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentNum . ' 0 R >>';

            $stream = '';
            foreach ($ops as $op) {
                if ($op['type'] === 'rect') {
                    $stream .= sprintf(
                        "%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n0 0 0 rg\n",
                        $op['color'][0],
                        $op['color'][1],
                        $op['color'][2],
                        $op['x'],
                        $op['y'],
                        $op['w'],
                        $op['h']
                    );
                    continue;
                }
                $font = $op['bold'] ? 'F2' : 'F1';
                $color = $op['color'];
                $stream .= sprintf(
                    "%.3F %.3F %.3F rg\nBT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
                    $color[0],
                    $color[1],
                    $color[2],
                    $font,
                    $op['size'],
                    self::MARGIN,
                    $op['y'],
                    $this->escapeText($op['text'])
                );
            }
            $objects[$contentNum] = ['stream' => $stream];
        }

        return $this->assemble($objects);
    }

    /**
     * The standard PDF fonts (WinAnsiEncoding, declared on the font objects above) are single-byte
     * — convert from the app's internal UTF-8 to CP1252 so German umlauts and the euro sign render
     * correctly, then escape PDF's own literal-string metacharacters.
     */
    private function escapeText(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /** @param array<int, string|array{stream: string}> $objects */
    private function assemble(array $objects): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $maxNum = max(array_keys($objects));
        $offsets = [];

        for ($n = 1; $n <= $maxNum; $n++) {
            $offsets[$n] = strlen($out);
            $body = $objects[$n];
            if (is_array($body)) {
                $streamData = $body['stream'] . "\n";
                $out .= "{$n} 0 obj\n<< /Length " . strlen($streamData) . " >>\nstream\n{$streamData}endstream\nendobj\n";
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
