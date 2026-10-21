<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';

class TopVerbraucher extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;

    private const STATUS_VARIABLES_SKIPPED = 202;

    private const PERIODS = ['today', 'week', 'month', 'year'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('TopN', 5);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('UpdateInterval', 5);

        $this->RegisterTimer('Calculate', 0, 'TVB_Calculate($_IPS[\'TARGET\']);');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 fehlt auch INSTANCE_VISUALIZATION_TYPE_HTML.
        // Deshalb steht für HTML der Wert 1 aus der Doku. Der Vollbildtyp (2) braucht 9.1, sonst bleibt die maximierte Ansicht leer.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function GetConfigurationForm(): string
    {
        return $this->sourceConfigurationForm();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $SenderID => $Messages) {
            foreach ($Messages as $Message) {
                $this->UnregisterMessage($SenderID, $Message);
            }
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->registerSourceMessages();

        $Config = $this->readConfiguration();
        $this->maintainVariables($Config['Unit']['Out'], $Config['Residents'], $Config['Area']);

        if ($Config['NoSources']) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Splitter Verbrauchsquellen verbunden', 0);
            $this->SetTimerInterval('Calculate', 0);
            $this->SetStatus(self::STATUS_NO_SOURCES);
            return;
        }

        if ($Config['Consumers'] === []) {
            $this->SetTimerInterval('Calculate', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('Calculate', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Calculate();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] == KR_READY) {
                    $this->ApplyChanges();
                }
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->scheduleReload();
                break;
            default:
                break;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->handleSourceAction($Ident, $Value)) {
            return;
        }
        switch ($Ident) {
            case 'Calculate':
                $this->Calculate();
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
                break;
        }
    }
    /**
     * Berechnet alle Zeiträume neu, schreibt die Variablen der Instanz und aktualisiert die Kachel.
     */
    public function Calculate(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        $Config = $this->readConfiguration();
        if ($Config['NoSources'] || $Config['Consumers'] === []) {
            return;
        }

        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SendDebug(__FUNCTION__, 'Keine Archiv-Instanz gefunden', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $Skipped = [];
        $Now = time();
        $Price = (float) $Config['FixedPrice'];
        $Data = [
            'unit'      => $Config['Unit']['Out'],
            'digits'    => $Config['Unit']['Out'] === 'm³' ? 3 : 2,
            'mainId'    => $Config['MainMeter'],
            'clickOpen' => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'periods'   => []
        ];

        foreach (self::PERIODS as $Period) {
            [$Start, $PrevStart, $PrevEnd] = $this->periodRange($Period, $Now);
            $Level = $Period === 'today' ? 0 : 1;

            $Current = $this->evaluate($Config, $ArchiveID, $Start, $Now, $Level, $Skipped);
            $Previous = $this->evaluate($Config, $ArchiveID, $PrevStart, $PrevEnd, $Level, $Skipped);

            $Costs = [
                $this->hourlyCost($Config, $ArchiveID, $Start, $Now, $Skipped),
                $this->hourlyCost($Config, $ArchiveID, $PrevStart, $PrevEnd, $Skipped)
            ];
            $Result = $this->buildPeriod($Config, $Current, $Previous, $Price, $Costs);
            // Kennzahlen je Bewohner und je Quadratmeter, wenn die Verbrauchsquellen sie kennen
            if ($Config['Residents'] > 0) {
                $Result['perResident'] = round($Result['total'] / $Config['Residents'], 3);
                $this->SetValue('PerResident_' . $Period, $Result['perResident']);
            }
            if ($Config['Area'] > 0) {
                $Result['perArea'] = round($Result['total'] / $Config['Area'], 4);
                $this->SetValue('PerArea_' . $Period, $Result['perArea']);
            }
            $Data['periods'][$Period] = $Result;

            $this->SetValue('Total_' . $Period, $Result['total']);
            $this->SetValue('Cost_' . $Period, $Result['cost']);
            $this->SetValue('Top_' . $Period, $this->rankingText($Result['nodes'], $Config['Unit']['Out']));
        }

        $Json = (string) json_encode($Data);
        $this->SetValue('Data', $Json);

        if ($Skipped !== []) {
            $this->SendDebug(__FUNCTION__, 'Übersprungen (kein Archiv oder falsche Einheit): ' . implode(', ', array_keys($Skipped)), 0);
            $this->SetStatus(self::STATUS_VARIABLES_SKIPPED);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        $this->UpdateVisualizationValue($this->tileMessage($Json));
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        $Json = $this->GetValue('Data');
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage($Json)) . ');</script>';
    }

    // ---------------------------------------------------------------- Konfiguration

    /**
     * Beginn des Zeitraums und Vorperiode. Die Vorperiode endet nach derselben verstrichenen Zeit,
     * damit der Vergleich mit dem laufenden Zeitraum fair bleibt (heute 14 Uhr gegen gestern 14 Uhr).
     */
    private function periodRange(string $Period, int $Now): array
    {
        $Today = new DateTimeImmutable('today');
        [$Start, $Step] = match ($Period) {
            'week'  => [$Today->modify('monday this week'), 'P1W'],
            'month' => [$Today->modify('first day of this month'), 'P1M'],
            'year'  => [$Today->setDate((int) $Today->format('Y'), 1, 1), 'P1Y'],
            default => [$Today, 'P1D']
        };
        $PrevStart = $Start->sub(new DateInterval($Step));
        return [$Start->getTimestamp(), $PrevStart->getTimestamp(), $PrevStart->getTimestamp() + ($Now - $Start->getTimestamp())];
    }

    /**
     * Kosten mit variablem Preis: Verbrauch des Hauptzählers je Stunde mal Preis der Stunde, aufsummiert.
     * Null, wenn das nicht geht (keine Preisvariable, kein Hauptzähler, nicht archiviert, Zähler ohne Zähler-Aggregation).
     * Dann gilt der feste Preis.
     */
    private function hourlyCost(array $Config, int $ArchiveID, int $Start, int $End, array &$Skipped): ?float
    {
        $PriceID = $Config['PriceID'];
        $MainID = $Config['MainMeter'];
        if ($PriceID <= 0 || $MainID <= 0) {
            return null;
        }
        if (!AC_GetLoggingStatus($ArchiveID, $PriceID)) {
            $Skipped[$PriceID] = 'archive';
            return null;
        }
        $Info = $this->unitInfoFor($MainID, $Config['Unit']);
        if ($Info['Family'] !== $Config['Unit']['Family']) {
            return null;
        }
        return $this->hourlyPriceCost($ArchiveID, $PriceID, $MainID, $Info, $Start, $End);
    }

    private function buildPeriod(array $Config, array $Current, array $Previous, float $Price, array $Costs): array
    {
        $Now = $this->buildTree('', $Config, $Current);
        $Before = $this->buildTree('', $Config, $Previous);
        $PrevValues = [];
        $this->flatten($Before, $PrevValues);

        $Sum = array_sum(array_column($Now, 'value'));
        $PrevSum = array_sum(array_column($Before, 'value'));

        $Main = $Config['MainMeter'] > 0 ? ($Current[$Config['MainMeter']] ?? null) : null;
        $PrevMain = $Config['MainMeter'] > 0 ? ($Previous[$Config['MainMeter']] ?? null) : null;
        $Total = $Main ?? $Sum;
        $PrevTotal = $PrevMain ?? $PrevSum;

        $Unrecorded = 0.0;
        $Negative = false;
        if ($Main !== null) {
            $Unrecorded = $Main - $Sum;
            if ($Unrecorded < 0) {
                $Negative = true;
                $Unrecorded = 0.0;
                $Total = $Sum;
            }
        }

        $TopN = max(1, $this->ReadPropertyInteger('TopN'));
        $Shown = array_slice($Now, 0, $TopN);
        $Others = array_slice($Now, $TopN);
        if ($Others !== []) {
            $Shown[] = ['id' => 'others', 'type' => 'others', 'name' => $this->Translate('Others'), 'value' => array_sum(array_column($Others, 'value')), 'children' => []];
        }
        $this->assignShares($Shown, $Total, $PrevValues);

        return [
            'total'      => round($Total, 4),
            'prevTotal'  => round($PrevTotal, 4),
            'cost'       => round($Costs[0] ?? $Total * $Price, 2),
            'prevCost'   => round($Costs[1] ?? $PrevTotal * $Price, 2),
            'unrecorded' => round($Unrecorded, 4),
            'negative'   => $Negative,
            'nodes'      => $Shown
        ];
    }

    private function assignShares(array &$Nodes, float $Total, array $PrevValues, float $ParentValue = 0.0): void
    {
        foreach ($Nodes as &$Node) {
            $Node['value'] = round($Node['value'], 4);
            $Node['share'] = $Total > 0 ? round($Node['value'] / $Total * 100, 1) : 0;
            $Node['shareGroup'] = $ParentValue > 0 ? round($Node['value'] / $ParentValue * 100, 1) : $Node['share'];
            $Node['prev'] = isset($PrevValues[$Node['id']]) ? round($PrevValues[$Node['id']], 4) : null;
            $this->assignShares($Node['children'], $Total, $PrevValues, $Node['value']);
        }
    }

    private function rankingText(array $Nodes, string $Unit): string
    {
        $Parts = [];
        foreach ($Nodes as $Node) {
            $Parts[] = $Node['name'] . ' ' . number_format($Node['value'], 2, '.', '') . ' ' . $Unit;
        }
        return implode('; ', $Parts);
    }

    // ---------------------------------------------------------------- Variablen und Kachel

    private function maintainVariables(string $Unit, int $Residents, float $Area): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Labels = [
            'today' => $this->Translate('Today'),
            'week'  => $this->Translate('This week'),
            'month' => $this->Translate('This month'),
            'year'  => $this->Translate('This year')
        ];
        $Position = 1;
        foreach (self::PERIODS as $Period) {
            $this->MaintainVariable('Total_' . $Period, $this->Translate('Consumption') . ' ' . $Labels[$Period], VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit, 'DIGITS' => 2], $Position++, true);
            $this->MaintainVariable('Cost_' . $Period, $this->Translate('Cost') . ' ' . $Labels[$Period], VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], $Position++, true);
            $this->MaintainVariable('PerResident_' . $Period, $this->Translate('Consumption per resident') . ' ' . $Labels[$Period], VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit, 'DIGITS' => 3], $Position++, $Residents > 0);
            $this->MaintainVariable('PerArea_' . $Period, $this->Translate('Consumption per m²') . ' ' . $Labels[$Period], VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit . '/m²', 'DIGITS' => 4], $Position++, $Area > 0);
            $this->MaintainVariable('Top_' . $Period, $this->Translate('Top consumers') . ' ' . $Labels[$Period], VARIABLETYPE_STRING, $Value, $Position++, true);
        }
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, $Position, true);
        $this->hideVariable('Data');
    }

    private function tileMessage(string $Json): string
    {
        $Data = json_decode($Json, true);
        $Data = is_array($Data) ? $Data : [];
        $Data['texts'] = [
            'total'       => $this->Translate('Total consumption'),
            'cost'        => $this->Translate('Cost'),
            'unrecorded'  => $this->Translate('Not recorded'),
            'negative'    => $this->Translate('The consumers add up to more than the main meter'),
            'ofTotal'     => $this->Translate('% of total'),
            'perResident' => $this->Translate('per resident'),
            'perArea'     => $this->Translate('per m²'),
            'openHint'    => $this->Translate('Click or tap again: open the history'),
            'ofGroup'     => $this->Translate('% of group'),
            'previous'    => $this->Translate('vs. previous period'),
            'empty'       => $this->Translate('No data yet'),
            'today'       => $this->Translate('Today'),
            'week'        => $this->Translate('Week'),
            'month'       => $this->Translate('Month'),
            'year'        => $this->Translate('Year')
        ];
        return (string) json_encode($Data);
    }
}
