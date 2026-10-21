<?php

declare(strict_types=1);

/**
 * Minimaler PDF-Schreiber ohne Fremdbibliothek: Seiten im A4-Hochformat mit Text (Helvetica, WinAnsi), Linien,
 * Rechtecken und Flächen aus Linienzügen. Koordinaten in Punkt, Ursprung oben links, y wächst nach unten.
 */
class SimplePdf
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    // Zeichenbreiten der Standardschriften Helvetica und Helvetica-Bold für ASCII 32 bis 126, in 1/1000 Schriftgröße
    private const REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584
    ];
    private const BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584
    ];
    // Umlaute und häufige Sonderzeichen nach dem Zeichen, dessen Breite sie haben
    private const WIDTH_ALIAS = [
        "\xE4" => 'a', "\xF6" => 'o', "\xFC" => 'u', "\xDF" => 'b', "\xC4" => 'A', "\xD6" => 'O', "\xDC" => 'U',
        "\x80" => '0', "\xB2" => '"', "\xB0" => '"', "\xB5" => 'u', "\xE9" => 'e', "\xE8" => 'e', "\x96" => '+', "\x97" => '+'
    ];

    private array $pages = [];
    private int $current = -1;
    private string $title;

    public function __construct(string $Title = '')
    {
        $this->title = $Title;
    }

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
        return $this->current + 1;
    }

    public function setPage(int $Number): void
    {
        $this->current = max(0, min(count($this->pages) - 1, $Number - 1));
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public static function hex(string $Color): array
    {
        $Color = ltrim($Color, '#');
        return [hexdec(substr($Color, 0, 2)) / 255, hexdec(substr($Color, 2, 2)) / 255, hexdec(substr($Color, 4, 2)) / 255];
    }

    public function fill(string $Color): void
    {
        [$R, $G, $B] = self::hex($Color);
        $this->add(sprintf('%s %s %s rg', $this->n($R), $this->n($G), $this->n($B)));
    }

    public function stroke(string $Color, float $Width = 0.5): void
    {
        [$R, $G, $B] = self::hex($Color);
        $this->add(sprintf('%s %s %s RG %s w', $this->n($R), $this->n($G), $this->n($B), $this->n($Width)));
    }

    public function dash(array $Pattern): void
    {
        $this->add('[' . implode(' ', array_map(fn (float $V): string => $this->n($V), $Pattern)) . '] 0 d');
    }

    public function rect(float $X, float $Y, float $W, float $H, string $Style = 'F'): void
    {
        $Op = ['F' => 'f', 'S' => 'S', 'FS' => 'B'][$Style] ?? 'f';
        $this->add(sprintf('%s %s %s %s re %s', $this->n($X), $this->n(self::HEIGHT - $Y - $H), $this->n($W), $this->n($H), $Op));
    }

    public function line(float $X1, float $Y1, float $X2, float $Y2): void
    {
        $this->add(sprintf('%s %s m %s %s l S', $this->n($X1), $this->n(self::HEIGHT - $Y1), $this->n($X2), $this->n(self::HEIGHT - $Y2)));
    }

    /**
     * Gefüllte Fläche aus Punkten [[x, y], ...].
     */
    public function polygon(array $Points): void
    {
        $Path = '';
        foreach ($Points as $Index => $Point) {
            $Path .= sprintf('%s %s %s ', $this->n($Point[0]), $this->n(self::HEIGHT - $Point[1]), $Index === 0 ? 'm' : 'l');
        }
        $this->add($Path . 'h f');
    }

    /**
     * Ringstück (oder Kreisstück bei Innenradius 0) von Winkel $From bis $To in Grad, 0 = oben, im Uhrzeigersinn.
     */
    public function sector(float $Cx, float $Cy, float $Outer, float $Inner, float $From, float $To): void
    {
        $Steps = max(2, (int) ceil(abs($To - $From) / 3));
        $Points = [];
        for ($i = 0; $i <= $Steps; $i++) {
            $A = deg2rad($From + ($To - $From) * $i / $Steps - 90);
            $Points[] = [$Cx + $Outer * cos($A), $Cy + $Outer * sin($A)];
        }
        if ($Inner > 0) {
            for ($i = $Steps; $i >= 0; $i--) {
                $A = deg2rad($From + ($To - $From) * $i / $Steps - 90);
                $Points[] = [$Cx + $Inner * cos($A), $Cy + $Inner * sin($A)];
            }
        } else {
            $Points[] = [$Cx, $Cy];
        }
        $this->polygon($Points);
    }

    /**
     * Text mit der Grundlinie bei $Y. Ausrichtung L, R oder C bezogen auf $X.
     */
    public function text(float $X, float $Y, string $Text, float $Size = 10, bool $Bold = false, string $Align = 'L'): void
    {
        $Encoded = $this->encode($Text);
        $Width = $this->encodedWidth($Encoded, $Size, $Bold);
        if ($Align === 'R') {
            $X -= $Width;
        } elseif ($Align === 'C') {
            $X -= $Width / 2;
        }
        $this->add(sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET', $Bold ? 'F2' : 'F1', $this->n($Size), $this->n($X), $this->n(self::HEIGHT - $Y), $this->escape($Encoded)));
    }

    public function textWidth(string $Text, float $Size = 10, bool $Bold = false): float
    {
        return $this->encodedWidth($this->encode($Text), $Size, $Bold);
    }

    /**
     * Kürzt den Text mit "..." so, dass er in die Breite passt.
     */
    public function fit(string $Text, float $MaxWidth, float $Size = 10, bool $Bold = false): string
    {
        if ($this->textWidth($Text, $Size, $Bold) <= $MaxWidth) {
            return $Text;
        }
        while ($Text !== '' && $this->textWidth($Text . '...', $Size, $Bold) > $MaxWidth) {
            $Text = mb_substr($Text, 0, mb_strlen($Text) - 1);
        }
        return $Text . '...';
    }

    public function output(): string
    {
        $Objects = [];
        $Objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $Kids = [];
        $Count = count($this->pages);
        for ($i = 0; $i < $Count; $i++) {
            $Kids[] = (5 + $i * 2) . ' 0 R';
        }
        $Objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $Kids) . '] /Count ' . $Count . ' >>';
        $Objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $Objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $Index => $Content) {
            $PageID = 5 + $Index * 2;
            $Objects[$PageID] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->n(self::WIDTH),
                $this->n(self::HEIGHT),
                $PageID + 1
            );
            $Objects[$PageID + 1] = '<< /Length ' . strlen($Content) . " >>\nstream\n" . $Content . "\nendstream";
        }
        $InfoID = 5 + $Count * 2;
        $Objects[$InfoID] = '<< /Title (' . $this->escape($this->encode($this->title)) . ') /Producer (Verbrauchsanalyse) >>';

        $Pdf = "%PDF-1.4\n";
        $Offsets = [];
        ksort($Objects);
        foreach ($Objects as $ID => $Body) {
            $Offsets[$ID] = strlen($Pdf);
            $Pdf .= $ID . " 0 obj\n" . $Body . "\nendobj\n";
        }
        $Xref = strlen($Pdf);
        $Pdf .= "xref\n0 " . ($InfoID + 1) . "\n0000000000 65535 f \n";
        for ($ID = 1; $ID <= $InfoID; $ID++) {
            $Pdf .= sprintf("%010d 00000 n \n", $Offsets[$ID]);
        }
        $Pdf .= 'trailer << /Size ' . ($InfoID + 1) . ' /Root 1 0 R /Info ' . $InfoID . " 0 R >>\nstartxref\n" . $Xref . "\n%%EOF\n";
        return $Pdf;
    }

    // ---------------------------------------------------------------- intern

    private function add(string $Operator): void
    {
        $this->pages[$this->current] .= $Operator . "\n";
    }

    private function n(float $Value): string
    {
        $Text = rtrim(rtrim(number_format($Value, 3, '.', ''), '0'), '.');
        return $Text === '' || $Text === '-0' ? '0' : $Text;
    }

    private function encode(string $Text): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($Text, 'Windows-1252', 'UTF-8');
        }
        return (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $Text);
    }

    private function escape(string $Text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $Text);
    }

    private function encodedWidth(string $Encoded, float $Size, bool $Bold): float
    {
        $Table = $Bold ? self::BOLD : self::REGULAR;
        $Sum = 0;
        $Length = strlen($Encoded);
        for ($i = 0; $i < $Length; $i++) {
            $Char = $Encoded[$i];
            if (isset(self::WIDTH_ALIAS[$Char])) {
                $Char = self::WIDTH_ALIAS[$Char];
            }
            $Code = ord($Char);
            $Sum += ($Code >= 32 && $Code <= 126) ? $Table[$Code - 32] : 556;
        }
        return $Sum * $Size / 1000;
    }
}
