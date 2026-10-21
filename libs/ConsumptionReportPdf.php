<?php

declare(strict_types=1);

require_once __DIR__ . '/SimplePdf.php';

/**
 * PDF des Verbrauchsberichts: Kennzahlen, Verlauf, Vergleich, Verteilung, größte Geräte und die Tabelle der gewählten Liste.
 * Die Klasse kennt keine Symcon-Funktionen. Texte kommen über die Übersetzungsfunktion, die Einstellungen zum Inhalt aus den Optionen
 * (DecimalComma, IncludeCost, IncludePrevious, IncludeIndicators, IncludeNotice).
 */
class ConsumptionReportPdf
{
    public const PALETTE = ['#12B69C', '#1F3A5F', '#E08A1E', '#6366F1', '#D64545', '#0EA5E9', '#B45309', '#64748B'];

    /**
     * @param \Closure(string): string $Translate
     * @param array<string, bool> $Options
     */
    public function __construct(private \Closure $Translate, private array $Options)
    {
    }

    /**
     * Baut das PDF: Kennzahlen, Verlauf, Vergleich, Verteilung, größte Geräte und die Tabelle der gewählten Liste.
     */
    public function build(array $D): string
    {
        $Comma = $this->Options['DecimalComma'];
        $Num = fn (float $Value, int $Digits): string => number_format($Value, $Digits, $Comma ? ',' : '.', '');
        $Signed = fn (float $Value): string => ($Value > 0 ? '+' : '') . $Num($Value, 0) . ' %';
        $Unit = (string) $D['unit'];
        $WithCost = $this->Options['IncludeCost'];

        $Navy = '#111C2D';
        $Accent = '#12B69C';
        $HeadColor = '#0B8F7A';
        $Ink = '#1F2937';
        $Muted = '#6B7280';
        $Soft = '#F1F5F9';
        $Grid = '#E2E8F0';
        $Good = '#2E9E5B';
        $Bad = '#D64545';
        $Neutral = '#94A3B8';
        $Palette = self::PALETTE;

        $Pdf = new SimplePdf($this->t('Consumption report') . ' ' . ($D['name'] ?? '') . ' ' . $D['label']);
        $Pdf->addPage();
        $Left = 40.0;
        $Width = SimplePdf::WIDTH - 80.0;
        $Right = $Left + $Width;
        $Bottom = SimplePdf::HEIGHT - 50.0;

        // Kopf
        $Pdf->fill($Navy);
        $Pdf->rect(0, 0, SimplePdf::WIDTH, 78);
        $Pdf->fill($Accent);
        $Pdf->rect(0, 78, SimplePdf::WIDTH, 4);
        $Pdf->fill('#FFFFFF');
        $Pdf->text($Left, 38, $this->t('Consumption report'), 22, true);
        $Pdf->text($Right, 38, (string) $D['label'], 15, true, 'R');
        $Pdf->text($Left, 60, sprintf($this->t('Created %s'), date('d.m.Y H:i')), 9);
        if (($D['name'] ?? '') !== '') {
            $Pdf->text($Right, 60, $Pdf->fit((string) $D['name'], $Width / 2, 10, true), 10, true, 'R');
        }
        $Y = 100.0;

        $Ensure = function (float $Height) use ($Pdf, &$Y, $Bottom): void
        {
            if ($Y + $Height > $Bottom) {
                $Pdf->addPage();
                $Y = 50.0;
            }
        };
        $Section = function (string $Title, float $Content = 20) use ($Pdf, &$Y, $Left, $Right, $HeadColor, $Grid, $Ensure): void
        {
            $Ensure(28 + $Content);
            $Pdf->fill($HeadColor);
            $Pdf->text($Left, $Y + 10, $Title, 11.5, true);
            $Pdf->stroke($Grid, 0.8);
            $Pdf->line($Left, $Y + 16, $Right, $Y + 16);
            $Y += 28;
        };

        // Kennzahlen
        $Cards = [[$this->t('Total consumption'), $Num($D['total'], 2) . ' ' . $Unit, '', $Ink]];
        if ($WithCost) {
            $Cards[] = [$this->t('Cost'), $Num($D['cost'], 2) . ' ' . "\xE2\x82\xAC", '', $Ink];
        }
        if ($D['co2'] !== null) {
            $Cards[] = [$this->t('CO2'), $Num($D['co2'], 1) . ' kg', '', $Ink];
        }
        if ($this->Options['IncludePrevious']) {
            if ($D['previous'] > 0) {
                $Cards[] = [$this->t('To the previous period'), $Signed($D['changePrevious']), $Num($D['previous'], 1) . ' ' . $Unit, $D['changePrevious'] > 0 ? $Bad : $Good];
            }
            if ($D['average'] > 0) {
                $Cards[] = [$this->t('To the average'), $Signed($D['changeAverage']), $Num($D['average'], 1) . ' ' . $Unit, $D['changeAverage'] > 0 ? $Bad : $Good];
            }
        }
        if ($D['lastYear'] !== null && $D['lastYear'] > 0) {
            $Cards[] = [$this->t('To the same period last year'), $Signed($D['changeLastYear']), $Num($D['lastYear'], 1) . ' ' . $Unit, $D['changeLastYear'] > 0 ? $Bad : $Good];
        }
        if ($this->Options['IncludeIndicators']) {
            if ($D['residents'] > 0) {
                $Cards[] = [$this->t('Per resident'), $Num($D['total'] / $D['residents'], 2) . ' ' . $Unit, '', $Ink];
            }
            if ($D['area'] > 0) {
                $Cards[] = [$this->t('Per m²'), $Num($D['total'] / $D['area'], 2) . ' ' . $Unit, '', $Ink];
            }
        }
        $CardWidth = ($Width - 30) / 4;
        foreach ($Cards as $Index => $Card) {
            if ($Index % 4 === 0 && $Index > 0) {
                $Y += 68;
            }
            $Ensure(66);
            $X = $Left + ($Index % 4) * ($CardWidth + 10);
            $Pdf->fill($Soft);
            $Pdf->rect($X, $Y, $CardWidth, 58);
            $Pdf->fill($Muted);
            $Pdf->text($X + 9, $Y + 15, $Pdf->fit($Card[0], $CardWidth - 18, 8), 8);
            $Pdf->fill($Card[3]);
            $Pdf->text($X + 9, $Y + 36, $Pdf->fit($Card[1], $CardWidth - 18, 14, true), 14, true);
            if ($Card[2] !== '') {
                $Pdf->fill($Muted);
                $Pdf->text($X + 9, $Y + 50, $Pdf->fit($Card[2], $CardWidth - 18, 7.5), 7.5);
            }
        }
        $Y += 72;

        if ($this->Options['IncludeNotice'] && $D['notice'] !== '') {
            $Ensure(34);
            $Pdf->fill('#FEF3C7');
            $Pdf->rect($Left, $Y, $Width, 26);
            $Pdf->fill('#92400E');
            $Pdf->text($Left + 10, $Y + 17, $Pdf->fit($D['notice'], $Width - 20, 10, true), 10, true);
            $Y += 38;
        }

        // Verlauf
        if (count($D['series']) > 1) {
            $Section($this->t('Course'), 165);
            $Values = array_column($D['series'], 'value');
            $Average = $D['average'] > 0 ? $D['average'] / count($Values) : 0.0;
            $Max = $this->niceMax(max(max($Values), $Average));
            $PlotLeft = $Left + 38;
            $PlotWidth = $Right - $PlotLeft;
            $PlotTop = $Y + 6;
            $PlotHeight = 120.0;
            // Achsentitel: links die Einheit, unter den Beschriftungen die Teilung
            $Pdf->fill($Muted);
            $Pdf->text($PlotLeft - 5, $PlotTop - 9, $Unit, 7.5, true, 'R');
            $Axis = ['hour' => 'Hour', 'day' => 'Day', 'month' => 'Month'][$D['bucket']] ?? 'Day';
            $Pdf->text($PlotLeft + $PlotWidth / 2, $PlotTop + $PlotHeight + 25, $this->t($Axis), 7.5, true, 'C');
            $Pdf->dash([]);
            for ($i = 0; $i <= 4; $i++) {
                $GridY = $PlotTop + $PlotHeight * (1 - $i / 4);
                $Pdf->stroke($Grid, 0.5);
                $Pdf->line($PlotLeft, $GridY, $Right, $GridY);
                $Pdf->fill($Muted);
                $Pdf->text($PlotLeft - 5, $GridY + 3, $Num($Max * $i / 4, fmod($Max / 4, 1.0) === 0.0 ? 0 : ($Max / 4 < 1 ? 2 : 1)), 7.5, false, 'R');
            }
            $Count = count($Values);
            $Slot = $PlotWidth / $Count;
            $BarWidth = $Slot * 0.68;
            $LabelStep = (int) ceil($Count / 12);
            $Pdf->fill($Accent);
            foreach ($D['series'] as $Index => $Item) {
                $BarHeight = $PlotHeight * min(1.0, $Item['value'] / $Max);
                $BarX = $PlotLeft + $Index * $Slot + ($Slot - $BarWidth) / 2;
                if ($BarHeight > 0.2) {
                    $Pdf->fill($Accent);
                    $Pdf->rect($BarX, $PlotTop + $PlotHeight - $BarHeight, $BarWidth, $BarHeight);
                }
                if ($Index % $LabelStep === 0) {
                    $Pdf->fill($Muted);
                    $Pdf->text($BarX + $BarWidth / 2, $PlotTop + $PlotHeight + 12, $Item['label'], 7.5, false, 'C');
                }
            }
            if ($Average > 0) {
                $AverageY = $PlotTop + $PlotHeight * (1 - min(1.0, $Average / $Max));
                $Pdf->stroke($Bad, 1);
                $Pdf->dash([4, 3]);
                $Pdf->line($PlotLeft, $AverageY, $Right, $AverageY);
                $Pdf->dash([]);
                $Pdf->fill($Bad);
                $Pdf->text($Right, $PlotTop - 1, $this->t('Average per interval') . ' (' . $Num($Average, 2) . ' ' . $Unit . ')', 7.5, false, 'R');
            }
            $Y += $PlotHeight + 38;
        }

        // Vergleich
        $Bars = [[$this->t('This period'), (float) $D['total'], $Accent]];
        if ($D['previous'] > 0) {
            $Bars[] = [$this->t('Previous period'), (float) $D['previous'], $Neutral];
        }
        if ($D['lastYear'] !== null && $D['lastYear'] > 0) {
            $Bars[] = [$this->t('Same period last year'), (float) $D['lastYear'], $Neutral];
        }
        if ($D['average'] > 0) {
            $Bars[] = [sprintf($this->t('Average of the last %d days'), $D['lookback']), (float) $D['average'], $Neutral];
        }
        if (count($Bars) > 1) {
            $Section($this->t('Comparison'), count($Bars) * 20);
            $this->pdfBars($Pdf, $Bars, $Left, $Width, $Y, $Num, $Unit, $Ink, $Muted, $Soft, $Ensure);
        }

        // Verteilung
        $Slices = $D['slices'];
        $Base = array_sum(array_column($Slices, 1));
        if ($Base > 0 && count($Slices) > 0) {
            $Rows = min(count($Slices), 9);
            $Height = max(140.0, $Rows * 17 + 10);
            $Section($this->t('Distribution'), $Height);
            $Cx = $Left + 70;
            $Cy = $Y + 70;
            $Angle = 0.0;
            foreach ($Slices as $Slice) {
                $Sweep = $Slice[1] / $Base * 360;
                $Pdf->fill($Slice[2]);
                $Pdf->sector($Cx, $Cy, 64, 36, $Angle, $Angle + min($Sweep, 359.9));
                $Angle += $Sweep;
            }
            $Pdf->fill($Ink);
            $Pdf->text($Cx, $Cy + 4, $Num($D['total'], 1), 11, true, 'C');
            $Pdf->fill($Muted);
            $Pdf->text($Cx, $Cy + 16, $Unit, 8, false, 'C');
            $LegendX = $Left + 160;
            foreach (array_slice($Slices, 0, 9) as $Index => $Slice) {
                $RowY = $Y + 8 + $Index * 17;
                $Pdf->fill($Slice[2]);
                $Pdf->rect($LegendX, $RowY - 8, 9, 9);
                $Pdf->fill($Ink);
                $Pdf->text($LegendX + 15, $RowY, $Pdf->fit($Slice[0], 150, 9), 9);
                $Pdf->fill($Muted);
                $Pdf->text($Right, $RowY, $Num($Slice[1], 2) . ' ' . $Unit . '   ' . $Num($Slice[1] / $Base * 100, 0) . ' %', 9, false, 'R');
            }
            $Y += $Height + 14;
        }

        // Größte Geräte
        $Devices = array_values(array_filter($D['allRows'], fn (array $Row): bool => $Row['type'] === 'var'));
        usort($Devices, fn (array $A, array $B): int => $B['value'] <=> $A['value']);
        $Devices = array_slice($Devices, 0, 8);
        if ($Devices !== []) {
            $Section($this->t('Largest devices'), count($Devices) * 20);
            $Bars = [];
            foreach ($Devices as $Index => $Device) {
                $Bars[] = [$Device['name'], (float) $Device['value'], $Palette[$Index % count($Palette)]];
            }
            $this->pdfBars($Pdf, $Bars, $Left, $Width, $Y, $Num, $Unit, $Ink, $Muted, $Soft, $Ensure);
        }

        // Tabelle der gewählten Liste
        if ($D['rows'] !== []) {
            $Section($this->t('Details'), 60);
            $Columns = [$Left + 4, $Left + $Width - 190, $Left + $Width - 125, $Left + $Width - 62, $Right - 4];
            $Header = function () use ($Pdf, &$Y, $Columns, $Muted, $Soft, $Left, $Width, $WithCost): void
            {
                $Pdf->fill($Soft);
                $Pdf->rect($Left, $Y - 11, $Width, 17);
                $Pdf->fill($Muted);
                $Pdf->text($Columns[0], $Y, $this->t('Name'), 8, true);
                $Pdf->text($Columns[1], $Y, $this->t('Consumption'), 8, true, 'R');
                $Pdf->text($Columns[2], $Y, $this->t('Share'), 8, true, 'R');
                if ($WithCost) {
                    $Pdf->text($Columns[3], $Y, $this->t('Cost'), 8, true, 'R');
                }
                $Pdf->text($Columns[4], $Y, $this->t('Change'), 8, true, 'R');
                $Y += 16;
            };
            $Y += 2;
            $Header();
            foreach ($D['rows'] as $Row) {
                if ($Y + 14 > $Bottom) {
                    $Pdf->addPage();
                    $Y = 60.0;
                    $Header();
                }
                $Bold = $Row['level'] === 0 && $Row['type'] === 'group';
                $Pdf->fill($Ink);
                $Pdf->text($Columns[0] + $Row['level'] * 12, $Y, $Pdf->fit($Row['name'], $Columns[1] - $Columns[0] - 80 - $Row['level'] * 12, 9, $Bold), 9, $Bold);
                $Pdf->text($Columns[1], $Y, $Num($Row['value'], 2) . ' ' . $Unit, 9, false, 'R');
                $Pdf->text($Columns[2], $Y, $Num($Row['share'], 0) . ' %', 9, false, 'R');
                if ($WithCost) {
                    $Pdf->text($Columns[3], $Y, $Num($Row['cost'], 2) . ' ' . "\xE2\x82\xAC", 9, false, 'R');
                }
                if ($Row['previous'] !== null && $Row['previous'] > 0) {
                    $Change = ($Row['value'] - $Row['previous']) / $Row['previous'] * 100;
                    $Pdf->fill($Change > 0 ? $Bad : $Good);
                    $Pdf->text($Columns[4], $Y, $Signed($Change), 9, false, 'R');
                }
                $Pdf->stroke($Grid, 0.4);
                $Pdf->line($Left, $Y + 5, $Right, $Y + 5);
                $Y += 15;
            }
        }

        // Fuß
        $Pages = $Pdf->pageCount();
        for ($Page = 1; $Page <= $Pages; $Page++) {
            $Pdf->setPage($Page);
            $Pdf->fill($Muted);
            $Pdf->text(SimplePdf::WIDTH / 2, SimplePdf::HEIGHT - 24, sprintf($this->t('Page %d of %d'), $Page, $Pages), 8, false, 'C');
        }
        return $Pdf->output();
    }

    /**
     * Gibt die Obergrenze einer Achse zurück, die auf 1, 2, 2,5, 5 oder 10 mal Zehnerpotenz aufgerundet ist.
     */
    private function niceMax(float $Value): float
    {
        if ($Value <= 0) {
            return 1.0;
        }
        $Power = 10 ** floor(log10($Value));
        foreach ([1, 2, 2.5, 5, 10] as $Step) {
            if ($Value <= $Step * $Power) {
                return $Step * $Power;
            }
        }
        return 10 * $Power;
    }

    /**
     * Waagerechte Balken mit Beschriftung links und Wert rechts. Der längste Balken füllt die verfügbare Breite.
     */
    private function pdfBars(SimplePdf $Pdf, array $Bars, float $Left, float $Width, float &$Y, callable $Num, string $Unit, string $Ink, string $Muted, string $Soft, callable $Ensure): void
    {
        $LabelWidth = 150.0;
        $ValueWidth = 90.0;
        $BarArea = $Width - $LabelWidth - $ValueWidth;
        $Max = max(array_column($Bars, 1)) ?: 1.0;
        $Total = array_sum(array_column($Bars, 1));
        foreach ($Bars as $Bar) {
            $Ensure(22);
            $Pdf->fill($Ink);
            $Pdf->text($Left, $Y + 11, $Pdf->fit($Bar[0], $LabelWidth - 8, 9), 9);
            $Pdf->fill($Soft);
            $Pdf->rect($Left + $LabelWidth, $Y + 2, $BarArea, 12);
            $Pdf->fill($Bar[2]);
            $Pdf->rect($Left + $LabelWidth, $Y + 2, max(1.0, $BarArea * $Bar[1] / $Max), 12);
            $Pdf->fill($Muted);
            $Pdf->text($Left + $Width, $Y + 11, $Num($Bar[1], 2) . ' ' . $Unit, 9, false, 'R');
            $Y += 20;
        }
        $Y += 10;
    }

    private function t(string $Key): string
    {
        return ($this->Translate)($Key);
    }
}
