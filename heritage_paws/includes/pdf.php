<?php
/**
 * HP_PDF - a small PDF writer for Heritage Paws. No external library needed.
 * Uses the built-in PDF fonts (Helvetica, Times-Italic, Courier), so every PDF viewer can open it.
 * Coordinates: $y is measured in points from the TOP of the page (A4 = 595 x 842 pt).
 */
class HP_PDF
{
    public const W = 595.28;
    public const H = 841.89;

    public float $ml = 50;      // left margin
    public float $mr = 50;      // right margin
    public float $mt = 48;      // top margin
    public float $mb = 56;      // bottom margin (footer lives here)
    public float $y  = 48;      // current position from the top

    public string $title     = 'Document';
    public string $author    = 'Heritage Paws';
    public string $watermark = '';
    public $headerFn = null;    // function (HP_PDF $pdf): void   - drawn at the top of every page
    public $footerFn = null;    // function (HP_PDF $pdf, int $page, int $total): void

    private array $pages  = [];
    private int   $cur    = -1;
    private array $images = [];

    private const FONTS = [
        'F1' => 'Helvetica',          // normal
        'F2' => 'Helvetica-Bold',     // bold
        'F3' => 'Times-Italic',       // used for typed signatures
        'F4' => 'Helvetica-Oblique',  // italic notes
        'F5' => 'Courier',            // fingerprint
    ];

    // Character widths (1000 units per em) for codes 32..255, taken from the standard font metrics
    private const WIDTHS = [
        'F1' => '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584 761 556 278 222 556 333 1000 556 556 333 1000 667 333 1000 278 611 278 278 222 222 333 333 350 556 1000 333 1000 500 333 944 278 500 667 278 333 556 556 556 556 260 556 333 737 370 556 584 333 737 333 400 584 333 333 333 556 537 278 333 333 365 556 834 834 834 611 667 667 667 667 667 667 1000 722 667 667 667 667 278 278 278 278 722 722 778 778 778 778 778 584 778 722 722 722 722 667 667 611 556 556 556 556 556 556 889 500 556 556 556 556 278 278 278 278 556 556 556 556 556 556 556 584 611 556 556 556 556 500 556 500',
        'F2' => '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584 761 556 278 278 556 500 1000 556 556 333 1000 667 333 1000 278 611 278 278 278 278 500 500 350 556 1000 333 1000 556 333 944 278 500 667 278 333 556 556 556 556 280 556 333 737 370 556 584 333 737 333 400 584 333 333 333 611 556 278 333 333 365 556 834 834 834 611 722 722 722 722 722 722 1000 722 667 667 667 667 278 278 278 278 722 722 778 778 778 778 778 584 778 722 722 722 722 667 667 611 556 556 556 556 556 556 889 556 556 556 556 556 278 278 278 278 611 611 611 611 611 611 611 584 611 611 611 611 611 556 611 556',
        'F3' => '250 333 420 500 500 833 778 214 333 333 500 675 250 333 250 278 500 500 500 500 500 500 500 500 500 500 333 333 675 675 675 500 920 611 611 667 722 611 611 722 722 333 444 667 556 833 667 722 611 722 611 500 556 722 611 833 611 556 556 389 278 389 422 500 333 500 500 444 500 444 278 500 500 278 278 444 278 722 500 500 500 500 389 389 278 500 444 667 444 444 389 400 275 400 541 761 500 250 333 500 556 889 500 500 333 1000 500 333 944 250 556 250 250 333 333 556 556 350 500 889 333 980 389 333 667 250 389 556 250 389 500 500 500 500 275 500 333 760 276 500 675 333 760 333 400 675 300 300 333 500 523 250 333 300 310 500 750 750 750 500 611 611 611 611 611 611 889 667 611 611 611 611 333 333 333 333 722 667 722 722 722 722 722 675 722 722 722 722 722 556 611 500 500 500 500 500 500 500 667 444 444 444 444 444 278 278 278 278 500 500 500 500 500 500 500 675 500 500 500 500 500 444 500 444',
    ];
    private static array $wcache = [];

    /* ---------------- text helpers ---------------- */

    // Converts UTF-8 text to the single-byte encoding the PDF fonts use (Windows-1252)
    public static function latin(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $s);
        if (function_exists('mb_convert_encoding')) {
            $prev = mb_substitute_character();
            mb_substitute_character(63);                       // '?' for anything not in Windows-1252
            $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            mb_substitute_character($prev);
        } else {
            $s = preg_replace('/[^\x20-\x7E\n]/', '?', $s);
        }
        return $s;
    }

    private static function wtable(string $f): array
    {
        if (!isset(self::$wcache[$f])) {
            if ($f === 'F5') {
                self::$wcache[$f] = array_fill(0, 224, 600);
            } else {
                $key = $f === 'F4' ? 'F1' : $f;
                self::$wcache[$f] = array_map('intval', explode(' ', self::WIDTHS[$key]));
            }
        }
        return self::$wcache[$f];
    }

    // Width in points of an already-converted string
    public function strWidth(string $s, string $f, float $size): float
    {
        $t = self::wtable($f);
        $w = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($s[$i]) - 32;
            $w += $t[$c] ?? 556;
        }
        return $w * $size / 1000;
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function rgb(array $c): string
    {
        return self::n($c[0] / 255) . ' ' . self::n($c[1] / 255) . ' ' . self::n($c[2] / 255);
    }

    /* ---------------- pages ---------------- */

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
        $this->y = $this->mt;
        if ($this->watermark !== '') {
            $this->drawWatermark();
        }
        if ($this->headerFn) {
            ($this->headerFn)($this);
        }
    }

    private function out(string $s): void
    {
        $this->pages[$this->cur] .= $s . "\n";
    }

    public function pageNo(): int
    {
        return $this->cur + 1;
    }

    public function limit(): float
    {
        return self::H - $this->mb;
    }

    // Starts a new page if less than $h points are left
    public function ensure(float $h): void
    {
        if ($this->cur < 0 || $this->y + $h > $this->limit()) {
            $this->addPage();
        }
    }

    private function drawWatermark(): void
    {
        $t    = self::latin($this->watermark);
        $size = 64;
        $w    = $this->strWidth($t, 'F2', $size);
        $cos  = 0.7071; $sin = 0.7071;
        $cx   = self::W / 2; $cy = self::H / 2;
        $tx   = $cx - ($w / 2) * $cos + ($size * 0.35) * $sin;
        $ty   = $cy - ($w / 2) * $sin - ($size * 0.35) * $cos;
        $this->out('q ' . self::n($cos) . ' ' . self::n($sin) . ' ' . self::n(-$sin) . ' ' . self::n($cos) . ' ' . self::n($tx) . ' ' . self::n($ty) . ' cm');
        $this->out('BT /F2 ' . $size . ' Tf 0.93 0.91 0.88 rg 0 0 Td (' . self::esc($t) . ') Tj ET Q');
    }

    /* ---------------- drawing primitives ---------------- */

    // Draws one line of text. $yTop = baseline position from the top of the page.
    public function text(float $x, float $yTop, string $s, string $f = 'F1', float $size = 10, array $rgb = [0, 0, 0], string $align = 'L', float $boxW = 0): void
    {
        $s = self::latin($s);
        if ($align !== 'L' && $boxW > 0) {
            $w = $this->strWidth($s, $f, $size);
            $x += $align === 'R' ? $boxW - $w : ($boxW - $w) / 2;
        }
        $this->out('BT /' . $f . ' ' . self::n($size) . ' Tf ' . self::rgb($rgb) . ' rg ' . self::n($x) . ' ' . self::n(self::H - $yTop) . ' Td (' . self::esc($s) . ') Tj ET');
    }

    public function rect(float $x, float $yTop, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.6): void
    {
        $op = '';
        if ($fill)   $op .= self::rgb($fill) . ' rg ';
        if ($stroke) $op .= self::rgb($stroke) . ' RG ' . self::n($lw) . ' w ';
        $paint = ($fill && $stroke) ? 'B' : ($fill ? 'f' : 'S');
        $this->out($op . self::n($x) . ' ' . self::n(self::H - $yTop - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $paint);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [0, 0, 0], float $lw = 0.6, bool $dash = false): void
    {
        $this->out(($dash ? '[3 3] 0 d ' : '[] 0 d ') . self::rgb($rgb) . ' RG ' . self::n($lw) . ' w '
            . self::n($x1) . ' ' . self::n(self::H - $y1) . ' m ' . self::n($x2) . ' ' . self::n(self::H - $y2) . ' l S');
    }

    /* ---------------- images ---------------- */

    // Loads a PNG/JPG with GD, optionally crops it (x,y,w,h in source pixels), flattens it on white and returns a JPEG
    public static function loadImage(string $path, ?array $crop = null, int $maxW = 700): ?array
    {
        if (!is_file($path) || !function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($path);
        $src = $raw ? @imagecreatefromstring($raw) : false;
        if (!$src) return null;
        $sw = imagesx($src); $sh = imagesy($src);
        [$cx, $cy, $cw, $ch] = $crop ?: [0, 0, $sw, $sh];
        $cw = min($cw, $sw - $cx); $ch = min($ch, $sh - $cy);
        $dw = min($maxW, $cw); $dh = (int)round($ch * $dw / $cw);
        $dst = imagecreatetruecolor($dw, $dh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);
        ob_start();
        imagejpeg($dst, null, 88);
        $jpeg = ob_get_clean();
        return $jpeg ? ['data' => $jpeg, 'w' => $dw, 'h' => $dh] : null;
    }

    public function image(array $img, float $x, float $yTop, float $drawW, float $drawH): void
    {
        $idx = array_search($img['data'], array_column($this->images, 'data'), true);
        if ($idx === false) {
            $this->images[] = $img;
            $idx = count($this->images) - 1;
        }
        $this->out('q ' . self::n($drawW) . ' 0 0 ' . self::n($drawH) . ' ' . self::n($x) . ' ' . self::n(self::H - $yTop - $drawH) . ' cm /Im' . ($idx + 1) . ' Do Q');
    }

    /* ---------------- flowing text ---------------- */

    // Splits "plain **bold** plain" into words, each tagged with its font and whether a space comes before it
    // (so "**Rex**," keeps the comma glued to the bold word)
    private function words(string $text): array
    {
        $text  = self::latin($text);
        $parts = explode('**', $text);
        $words = [];
        $space = false;
        foreach ($parts as $i => $part) {
            $font = $i % 2 ? 'F2' : 'F1';
            foreach (preg_split('/( +)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                if (trim($piece) === '') { $space = true; continue; }
                $words[] = [$piece, $font, $space];
                $space = false;
            }
        }
        return $words;
    }

    // Wraps text to a width. Returns lines; each line is a list of [text, font, width, spaceBefore].
    // "\n" forces a new line. Words glued together by bold markers are never split across lines.
    public function wrap(string $text, float $size, float $maxW, string $baseFont = 'F1'): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $para = rtrim($para);
            if ($para === '') { $lines[] = []; continue; }
            $space = $this->strWidth(' ', $baseFont, $size);

            // 1. chunks = runs of glued words
            $chunks = [];
            foreach ($this->words($para) as [$w, $f, $sp]) {
                if ($baseFont !== 'F1' && $f === 'F1') $f = $baseFont;
                $tok = [$w, $f, $this->strWidth($w, $f, $size), 1];
                if ($sp || !$chunks) { $chunks[] = ['t' => [$tok], 'w' => $tok[2]]; }
                else { $tok[3] = 0; $chunks[count($chunks) - 1]['t'][] = $tok; $chunks[count($chunks) - 1]['w'] += $tok[2]; }
            }

            // 2. fill lines
            $cur = []; $curW = 0;
            foreach ($chunks as $ch) {
                // a single very long word is broken into pieces
                while ($ch['w'] > $maxW && count($ch['t']) === 1) {
                    [$w, $f] = $ch['t'][0];
                    $cut = max(1, (int)floor(strlen($w) * $maxW / $ch['w']) - 1);
                    if ($cur) { $lines[] = $cur; $cur = []; $curW = 0; }
                    $head = substr($w, 0, $cut); $w = substr($w, $cut);
                    $lines[] = [[$head, $f, $this->strWidth($head, $f, $size), 0]];
                    $ww = $this->strWidth($w, $f, $size);
                    $ch = ['t' => [[$w, $f, $ww, 1]], 'w' => $ww];
                }
                $add = ($cur ? $space : 0) + $ch['w'];
                if ($cur && $curW + $add > $maxW) {
                    $lines[] = $cur; $cur = []; $curW = 0; $add = $ch['w'];
                }
                foreach ($ch['t'] as $k => $tok) {
                    $tok[3] = ($k === 0 && $cur) ? 1 : 0;      // space only between chunks, never at line start
                    $cur[] = $tok;
                }
                $curW += $add;
            }
            if ($cur) $lines[] = $cur;
        }
        return $lines;
    }

    // Draws a wrapped line (from wrap()) starting at $x; $yTop is the baseline
    public function drawLine(array $line, float $x, float $yTop, float $size, array $rgb, string $baseFont = 'F1'): void
    {
        $space = $this->strWidth(' ', $baseFont, $size);
        foreach ($line as $i => [$w, $f, $ww, $sp]) {
            if ($i > 0 && $sp) $x += $space;
            $this->out('BT /' . $f . ' ' . self::n($size) . ' Tf ' . self::rgb($rgb) . ' rg ' . self::n($x) . ' ' . self::n(self::H - $yTop) . ' Td (' . self::esc($w) . ') Tj ET');
            $x += $ww;
        }
    }

    /**
     * Writes a paragraph at the current position and moves $y down. Handles page breaks.
     * Options: x, w, size, lh (line height), font, color, after (gap below)
     */
    public function para(string $text, array $o = []): void
    {
        $x     = $o['x']     ?? $this->ml;
        $w     = $o['w']     ?? (self::W - $this->mr - $x);
        $size  = $o['size']  ?? 10;
        $lh    = $o['lh']    ?? $size * 1.42;
        $font  = $o['font']  ?? 'F1';
        $color = $o['color'] ?? [40, 33, 24];
        $after = $o['after'] ?? 6;
        $num   = $o['num']   ?? null;     // clause number drawn in the margin of the first line

        $lines = $this->wrap($text, $size, $w, $font);
        $count = count($lines);
        foreach ($lines as $i => $line) {
            // avoid a single stray line at the bottom or top of a page
            $this->ensure((($i === 0 && $count > 1) || ($i === $count - 2 && $count > 2)) ? 2 * $lh : $lh);
            $base = $this->y + $size;
            if ($i === 0 && $num !== null) {
                $this->text($x - ($o['numw'] ?? 0), $base, $num, 'F2', $size, [107, 68, 35]);
            }
            $this->drawLine($line, $x, $base, $size, $color, $font);
            $this->y += $lh;
        }
        $this->y += $after;
    }

    /* ---------------- output ---------------- */

    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $total = count($this->pages);
        if ($this->footerFn) {
            for ($i = 0; $i < $total; $i++) {
                $this->cur = $i;
                ($this->footerFn)($this, $i + 1, $total);
            }
        }

        $objs = [];                       // id => ['d' => dictionary text, 's' => stream bytes|null]
        $objs[1] = ['d' => '<< /Type /Catalog /Pages 2 0 R >>'];
        $id = 3;
        $fontRes = '';
        foreach (self::FONTS as $key => $base) {
            $objs[$id] = ['d' => '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>'];
            $fontRes .= '/' . $key . ' ' . $id . ' 0 R ';
            $id++;
        }
        $imgRes = '';
        foreach ($this->images as $i => $im) {
            $objs[$id] = [
                'd' => '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h']
                     . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
                's' => $im['data'],
            ];
            $imgRes .= '/Im' . ($i + 1) . ' ' . $id . ' 0 R ';
            $id++;
        }
        $res = '<< /Font << ' . $fontRes . '>>' . ($imgRes ? ' /XObject << ' . $imgRes . '>>' : '') . ' >>';

        $kids = [];
        foreach ($this->pages as $content) {
            $cid = $id; $pid = $id + 1; $id += 2;
            $deflate = function_exists('gzcompress');
            $objs[$cid] = [
                'd' => '<<' . ($deflate ? ' /Filter /FlateDecode' : ''),
                's' => $deflate ? gzcompress($content, 6) : $content,
            ];
            $objs[$pid] = ['d' => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n(self::W) . ' ' . self::n(self::H) . '] /Resources ' . $res . ' /Contents ' . $cid . ' 0 R >>'];
            $kids[] = $pid . ' 0 R';
        }
        $objs[2] = ['d' => '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>'];

        $infoId = $id;
        $objs[$infoId] = ['d' => '<< /Title (' . self::esc(self::latin($this->title)) . ') /Author (' . self::esc(self::latin($this->author))
            . ') /Creator (Heritage Paws) /CreationDate (D:' . date('YmdHis') . ') >>'];
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $oid => $o) {
            $offsets[$oid] = strlen($pdf);
            if (isset($o['s'])) {
                $pdf .= $oid . " 0 obj\n" . $o['d'] . ' /Length ' . strlen($o['s']) . " >>\nstream\n" . $o['s'] . "\nendstream\nendobj\n";
            } else {
                $pdf .= $oid . " 0 obj\n" . $o['d'] . "\nendobj\n";
            }
        }
        $xref = strlen($pdf);
        $max  = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . ' /Root 1 0 R /Info ' . $infoId . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}
