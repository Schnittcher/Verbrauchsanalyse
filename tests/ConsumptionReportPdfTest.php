<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

include_once __DIR__ . '/../libs/ConsumptionReportPdf.php';

class ConsumptionReportPdfTest extends TestCase
{
    public function testPdfIsBuiltWithNameAndKeyFigures(): void
    {
        $pdf = $this->build($this->data(['name' => 'Strom monatlich']));

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertStringContainsString('Strom monatlich', $pdf);
        $this->assertStringContainsString('188,07', $pdf);
    }

    public function testPdfWithoutRowsAndSeriesStillWorks(): void
    {
        // Heizung: keine Zeilen, keine Verteilung, kein Verlauf
        $pdf = $this->build($this->data(['rows' => [], 'allRows' => [], 'nodes' => [], 'slices' => [], 'series' => [], 'co2' => null]));

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
    }

    public function testCostCardFollowsOption(): void
    {
        $with = $this->build($this->data([]), ['IncludeCost' => true]);
        $without = $this->build($this->data([]), ['IncludeCost' => false]);

        $this->assertStringContainsString('49,45', $with);
        $this->assertStringNotContainsString('49,45', $without);
    }

    private function build(array $data, array $options = []): string
    {
        $options += ['DecimalComma' => true, 'IncludeCost' => true, 'IncludePrevious' => true, 'IncludeIndicators' => false, 'IncludeNotice' => true];
        return (new ConsumptionReportPdf(fn (string $Key): string => $Key, $options))->build($data);
    }

    private function data(array $override): array
    {
        $series = [];
        for ($Day = 1; $Day <= 5; $Day++) {
            $series[] = ['label' => (string) $Day, 'title' => $Day . '.09.2026', 'value' => 5.0 + $Day / 10];
        }
        $row = ['level' => 0, 'type' => 'var', 'name' => 'Kuehlschrank', 'value' => 14.39, 'share' => 7.7, 'cost' => 3.78, 'previous' => 14.0];
        return array_merge([
            'name'           => 'Bericht',
            'label'          => '09/2026',
            'unit'           => 'kWh',
            'total'          => 188.07,
            'cost'           => 49.45,
            'previous'       => 177.0,
            'average'        => 154.0,
            'lastYear'       => null,
            'changePrevious' => 6.2,
            'changeAverage'  => 22.0,
            'changeLastYear' => 0.0,
            'notice'         => 'Notice',
            'nodes'          => [['id' => 'v:1', 'type' => 'var', 'name' => 'Kuehlschrank', 'value' => 14.39, 'color' => '', 'children' => []]],
            'allRows'        => [$row],
            'rows'           => [$row],
            'residents'      => 3,
            'area'           => 120.0,
            'co2'            => 71.5,
            'lookback'       => 90,
            'period'         => 2,
            'series'         => $series,
            'slices'         => [['Kuehlschrank', 14.39, '#12B69C'], ['Not recorded', 173.68, '#CBD5E1']],
            'bucket'         => 'day'
        ], $override);
    }
}
