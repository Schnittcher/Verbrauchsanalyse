<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ReferenceTablesStrict.php';

/**
 * Heizspiegel: Ordnet die Heizenergie des Gebäudes je Quadratmeter und Jahr in die Kategorien niedrig, mittel, erhöht und zu hoch
 * des Heizspiegels für Deutschland ein, nach Energieträger und Gebäudefläche. Die Heizung (Variable, Energieträger, Heizwert) und die
 * Fläche kommen aus den Verbrauchsquellen. Dazu die Heizkosten je Quadratmeter, wenn dort ein Preis steht, und das Einsparpotenzial.
 */
class Heizspiegel extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ReferenceTables;

    private const BASIS_ROLLING = 0;
    private const BASIS_LAST_YEAR = 1;
    private const BASIS_THIS_YEAR = 2;

    // Kategorien des Heizspiegels
    private const CATEGORIES = ['low', 'medium', 'raised', 'high'];

    // Ungefährer Wert einer Einheit Heizöl (Liter) oder Erdgas (m³) nach dem Heizspiegel, wenn kein Heizwert eingetragen ist
    private const DEFAULT_KWH_PER_UNIT = 10.0;

    // Vorgabe der Referenztabelle: Heizspiegel für Deutschland 2025 (Abrechnungsjahr 2024), co2online
    private const DEFAULT_YEAR = 2025;

    /**
     * Gebäudefläche ab (m²), Energieträger, Obergrenzen niedrig, mittel und erhöht in kWh je m² und Jahr, dann in Euro je m² und Jahr.
     * Oberhalb der dritten Grenze gilt "zu hoch". Quelle: Heizspiegel für Deutschland 2025 (co2online, heizspiegel.de).
     */
    private const DEFAULT_TABLE = [
        80 => [
            'gas'      => [[62, 121, 207], [6.90, 13.60, 23.20]],
            'oil'      => [[100, 165, 263], [9.90, 16.40, 26.10]],
            'district' => [[38, 89, 191], [6.40, 15.20, 32.50]],
            'heatpump' => [[20, 36, 82], [5.10, 9.20, 21.10]],
            'pellets'  => [[74, 148, 249], [4.40, 8.80, 14.80]]
        ],
        150 => [
            'gas'      => [[64, 116, 187], [7.20, 13.00, 21.00]],
            'oil'      => [[92, 142, 220], [9.10, 14.10, 21.80]],
            'district' => [[41, 94, 169], [7.00, 16.10, 28.70]],
            'heatpump' => [[18, 33, 77], [4.60, 8.50, 19.80]],
            'pellets'  => [[72, 126, 219], [4.30, 7.50, 13.00]]
        ],
        250 => [
            'gas'      => [[61, 114, 185], [9.00, 14.70, 22.30]],
            'oil'      => [[78, 123, 197], [10.30, 14.70, 22.00]],
            'district' => [[36, 112, 203], [7.80, 17.50, 29.50]],
            'heatpump' => [[17, 30, 69], [6.30, 9.70, 19.70]],
            'pellets'  => [[60, 113, 196], [5.80, 8.80, 13.30]]
        ],
        500 => [
            'gas'      => [[59, 115, 177], [8.70, 14.80, 21.50]],
            'oil'      => [[68, 127, 198], [9.30, 15.10, 22.20]],
            'district' => [[46, 87, 144], [9.70, 16.30, 25.70]]
        ]
    ];

    // Zahlen der Auswahl im Formular für die Energieträger der Tabelle
    private const CARRIER_IDS = ['gas' => 1, 'oil' => 2, 'district' => 3, 'heatpump' => 4, 'pellets' => 5];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyFloat('HotWaterKWh', 24.0);
        $this->RegisterPropertyFloat('HotWaterKWhHeatPump', 9.6);
        $this->RegisterPropertyFloat('HotWaterCost', 2.65);
        $this->RegisterPropertyFloat('HotWaterCostHeatPump', 2.45);
        $this->RegisterPropertyInteger('Basis', self::BASIS_ROLLING);
        $this->RegisterPropertyInteger('UpdateInterval', 720);
        $this->RegisterPropertyString('Tables', (string) json_encode($this->referenceTreeFromFlat($this->defaultRows(self::DEFAULT_YEAR))));
        $this->RegisterPropertyInteger('NewYear', (int) date('Y'));

        $this->RegisterAttributeString('View', 'current');
        $this->RegisterTimer('Refresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Refresh\', \'\');');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 steht für HTML der Wert 1 aus der Doku.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage()) . ');</script>';
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

        $this->maintainVariables();

        $Sources = $this->requestSources();
        if ($Sources === null) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Splitter Verbrauchsquellen verbunden', 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(self::STATUS_NO_SOURCES);
            return;
        }

        $Error = $this->validate($Sources);
        if ($Error !== '') {
            $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            // Die Kachel erklärt, was fehlt
            $this->SetValue('Data', (string) json_encode(['error' => $Error]));
            $this->UpdateVisualizationValue($this->tileMessage());
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('Refresh', $this->ReadPropertyInteger('UpdateInterval') * 60000);
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->refresh();
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
        if ($Ident === 'Refresh') {
            $this->refresh();
            return;
        }
        if ($Ident === 'Load') {
            $View = (string) $Value;
            if ($View !== 'current' && !in_array((int) $View, array_keys($this->tables()), true)) {
                return;
            }
            $this->WriteAttributeString('View', $View);
            $this->refresh();
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Berechnet die Einordnung neu, setzt die Variablen und aktualisiert die Kachel.
     */
    public function Update(): void
    {
        $this->refresh();
    }

    private function extendConfigurationForm(array &$Form): void
    {
        $this->overrideVisibility($Form);
    }

    // ---------------------------------------------------------------- Referenztabellen

    private function referenceDefaultYear(): int
    {
        return self::DEFAULT_YEAR;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function referenceDefaultRows(int $Year): array
    {
        return $this->defaultRows($Year);
    }

    /**
     * Die Spalten einer Gruppenzeile (Jahr) im Baum, ohne Werte.
     *
     * @return array<string, mixed>
     */
    private function referenceGroupRow(): array
    {
        return ['Area' => 0, 'Carrier' => 0, 'KWh1' => 0, 'KWh2' => 0, 'KWh3' => 0, 'Cost1' => 0, 'Cost2' => 0, 'Cost3' => 0];
    }

    /**
     * Die Vorgabe als flache Zeilen: Jahr, Gebäudefläche ab, Energieträger, Grenzen in kWh und in Euro je m² und Jahr.
     *
     * @return array<int, array<string, mixed>>
     */
    private function defaultRows(int $Year): array
    {
        $Rows = [];
        foreach (self::DEFAULT_TABLE as $Area => $Carriers) {
            foreach ($Carriers as $Carrier => [$KWh, $Cost]) {
                $Rows[] = [
                    'Year'  => $Year, 'Area' => $Area, 'Carrier' => self::CARRIER_IDS[$Carrier],
                    'KWh1'  => $KWh[0], 'KWh2' => $KWh[1], 'KWh3' => $KWh[2],
                    'Cost1' => $Cost[0], 'Cost2' => $Cost[1], 'Cost3' => $Cost[2]
                ];
            }
        }
        return $Rows;
    }

    /**
     * Die Referenztabellen aus der Instanz: [Jahr][Gebäudefläche ab][Energieträger] => ['kwh' => [3], 'cost' => [3]].
     * Zeilen mit nicht aufsteigenden Werten in kWh fallen weg.
     *
     * @return array<int, array<int, array<string, array<string, array<int, float>>>>>
     */
    private function tables(): array
    {
        $Tables = [];
        $Carriers = array_flip(self::CARRIER_IDS);
        foreach ($this->referenceFlatRows() as $Row) {
            $KWh = [(float) ($Row['KWh1'] ?? 0), (float) ($Row['KWh2'] ?? 0), (float) ($Row['KWh3'] ?? 0)];
            $Cost = [(float) ($Row['Cost1'] ?? 0), (float) ($Row['Cost2'] ?? 0), (float) ($Row['Cost3'] ?? 0)];
            $Year = (int) ($Row['Year'] ?? 0);
            $Area = (int) ($Row['Area'] ?? 0);
            $Carrier = $Carriers[(int) ($Row['Carrier'] ?? 0)] ?? null;
            if ($Carrier !== null && $Year >= 2000 && $Area >= 1 && $KWh[0] > 0 && $KWh[1] > $KWh[0] && $KWh[2] > $KWh[1]) {
                $Tables[$Year][$Area][$Carrier] = ['kwh' => $KWh, 'cost' => $Cost];
            }
        }
        foreach ($Tables as &$Areas) {
            ksort($Areas);
        }
        unset($Areas);
        krsort($Tables);
        return $Tables;
    }

    // ---------------------------------------------------------------- Berechnung

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Sources = $this->requestSources();
        $ArchiveID = $this->getArchiveID();
        if ($Sources === null) {
            return;
        }
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Die Variablen zeigen immer die aktuelle Einordnung, die Kachel zusätzlich das gewählte Jahr
        $Current = $this->evaluateView($Sources, $ArchiveID, 'current');
        $View = $this->ReadAttributeString('View');
        $Years = array_keys($this->tables());
        $Selected = $View !== 'current' && in_array((int) $View, $Years, true) ? $View : 'current';
        $Shown = $Selected === 'current' ? $Current : $this->evaluateView($Sources, $ArchiveID, $Selected);

        if (!isset($Current['error'])) {
            $this->SetValue('Category', $this->Translate(['low', 'medium', 'raised', 'too high'][$Current['category']]));
            $this->SetValue('KWhPerM2', $Current['perM2']);
            $this->SetValue('CostPerM2', $Current['costPerM2'] ?? 0.0);
            $this->SetValue('SavingKWh', $Current['toNextKWh']);
            $this->SetValue('SavingCost', $Current['toNextCost'] ?? 0.0);
        }
        $this->SetValue('Data', (string) json_encode($Shown + ['years' => $Years, 'selected' => $Selected, 'updated' => time()]));
        $this->UpdateVisualizationValue($this->tileMessage());
    }

    /**
     * Energieträger des Heizspiegels zu dem der Verbrauchsquellen, oder null, wenn es dafür keine Zeilen gibt (Sonstiges, keine Angabe).
     */
    private function tableCarrier(string $Carrier): ?string
    {
        return ['gas' => 'gas', 'oil' => 'oil', 'district' => 'district', 'pellets' => 'pellets', 'electric' => 'heatpump'][$Carrier] ?? null;
    }

    /**
     * Umrechnung der Heizungsvariable in kWh je Einheit. Misst sie Energie, ist der Faktor 1. Sonst gilt der Heizwert aus den
     * Verbrauchsquellen, bei Erdgas (m³) und Heizöl (Liter) ohne Heizwert der ungefähre Wert des Heizspiegels. Null, wenn nichts passt.
     */
    private function kwhPerUnit(array $Heating, array $Info): ?float
    {
        if ($Info['Family'] === 'energy') {
            return 1.0;
        }
        if ((float) $Heating['EnergyPerUnit'] > 0) {
            return (float) $Heating['EnergyPerUnit'];
        }
        return in_array($Heating['Carrier'], ['gas', 'oil'], true) ? self::DEFAULT_KWH_PER_UNIT : null;
    }

    /**
     * Ordnet die Heizenergie ein. 'current' nimmt den eingestellten Zeitraum und die neueste Tabelle, ein Jahr den Verbrauch dieses
     * Kalenderjahres und die Tabelle desselben Jahres. Bei einem Fehler steht 'error' im Ergebnis.
     */
    private function evaluateView(array $Sources, int $ArchiveID, string $View): array
    {
        $Tables = $this->tables();
        if ($Tables === []) {
            return ['error' => 'tables'];
        }
        $TableYear = $View === 'current' ? (int) array_key_first($Tables) : (int) $View;
        $Heating = $Sources['Heating'];
        $Carrier = $this->tableCarrier((string) $Heating['Carrier']);
        $Area = (float) ($Sources['Area'] ?? 0.0);

        // Passende Größenklasse: die größte, die nicht über der Fläche liegt, und die nächstkleinere, wenn der Energieträger dort fehlt
        $Row = null;
        $AreaClass = 0;
        $Classes = array_keys($Tables[$TableYear] ?? []);
        rsort($Classes);
        foreach ($Classes as $Class) {
            if ($Class <= $Area && isset($Tables[$TableYear][$Class][$Carrier])) {
                $Row = $Tables[$TableYear][$Class][$Carrier];
                $AreaClass = $Class;
                break;
            }
        }
        if ($Row === null) {
            // Unterhalb der kleinsten Klasse gilt die kleinste mit dem Energieträger
            sort($Classes);
            foreach ($Classes as $Class) {
                if (isset($Tables[$TableYear][$Class][$Carrier])) {
                    $Row = $Tables[$TableYear][$Class][$Carrier];
                    $AreaClass = $Class;
                    break;
                }
            }
        }
        if ($Row === null) {
            return ['error' => 'row', 'tableYear' => $TableYear];
        }

        $HeatingID = (int) $Heating['ID'];
        $Info = $this->unitInfoFor($HeatingID, $Sources['Unit']);
        $PerUnit = $this->kwhPerUnit($Heating, $Info);
        if ($PerUnit === null || !AC_GetLoggingStatus($ArchiveID, $HeatingID)) {
            return ['error' => 'source'];
        }

        $Range = $this->range($View === 'current' ? null : (int) $View);
        $Units = $this->consumptionBetween($ArchiveID, $HeatingID, $Info, $Range['start'], $Range['end'], 1);
        $Days = max(1, (int) round(($Range['end'] - $Range['start']) / 86400));
        $YearlyUnits = $Range['annualize'] ? $Units / $Days * 365 : $Units;
        $YearlyKWh = $YearlyUnits * $PerUnit;

        // Durchlauferhitzer oder Boiler: Der Heizspiegel rechnet pauschal je Quadratmeter dazu
        $HeatPump = $Carrier === 'heatpump';
        $WaterKWh = 0.0;
        $WaterCost = 0.0;
        if (!empty($Sources['HotWaterElectric'])) {
            $WaterKWh = $HeatPump ? $this->ReadPropertyFloat('HotWaterKWhHeatPump') : $this->ReadPropertyFloat('HotWaterKWh');
            $WaterCost = $HeatPump ? $this->ReadPropertyFloat('HotWaterCostHeatPump') : $this->ReadPropertyFloat('HotWaterCost');
        }
        $PerM2 = $YearlyKWh / $Area + $WaterKWh;
        $Category = $this->categoryOf($PerM2, $Row['kwh']);

        // Kosten nur mit einem Preis je Einheit der Heizungsvariable
        $Price = (float) $Heating['Price'];
        $CostPerM2 = null;
        $CostCategory = null;
        $PricePerKWh = null;
        if ($Price > 0) {
            $CostPerM2 = $YearlyUnits * $Price / $Area + $WaterCost;
            $CostCategory = $Row['cost'][0] > 0 ? $this->categoryOf($CostPerM2, $Row['cost']) : null;
            $PricePerKWh = $Price / $PerUnit;
        }

        // Bis zur nächstbesseren Kategorie und bis niedrig
        $ToNext = $Category > 0 ? max(0.0, ($PerM2 - $Row['kwh'][$Category - 1]) * $Area) : 0.0;
        $ToLow = max(0.0, ($PerM2 - $Row['kwh'][0]) * $Area);

        return [
            'category'     => $Category,
            'label'        => self::CATEGORIES[$Category],
            'perM2'        => round($PerM2, 1),
            'perM2Net'     => round($YearlyKWh / $Area, 1),
            'hotWaterAdd'  => $WaterKWh,
            'bounds'       => $Row['kwh'],
            'costBounds'   => $Row['cost'],
            'costPerM2'    => $CostPerM2 === null ? null : round($CostPerM2, 2),
            'costCategory' => $CostCategory,
            'area'         => $Area,
            'areaClass'    => $AreaClass,
            'belowRange'   => $Area < (float) min($Classes),
            'carrier'      => (string) $Heating['Carrier'],
            'yearlyKWh'    => round($YearlyKWh, 0),
            'toNextKWh'    => round($ToNext, 0),
            'toLowKWh'     => round($ToLow, 0),
            'toNextCost'   => $PricePerKWh === null ? null : round($ToNext * $PricePerKWh, 0),
            'toLowCost'    => $PricePerKWh === null ? null : round($ToLow * $PricePerKWh, 0),
            'tableYear'    => $TableYear,
            'basisLabel'   => $Range['label'],
            'annualized'   => $Range['annualize'],
            'days'         => $Days,
            'position'     => round($this->position($PerM2, $Row['kwh']), 4)
        ];
    }

    /**
     * Kategorie: 0 niedrig bis zur ersten Grenze, 1 mittel, 2 erhöht, 3 zu hoch darüber.
     *
     * @param array<int, float> $Bounds
     */
    private function categoryOf(float $Value, array $Bounds): int
    {
        foreach ($Bounds as $Index => $Limit) {
            if ($Value <= $Limit) {
                return $Index;
            }
        }
        return 3;
    }

    /**
     * Zeitraum der Auswertung. Mit Jahr: dieses Kalenderjahr, das laufende wird hochgerechnet. Ohne Jahr der eingestellte Zeitraum.
     */
    private function range(?int $Year): array
    {
        $Today = new DateTimeImmutable('today');
        if ($Year !== null) {
            $Start = $Today->setDate($Year, 1, 1);
            if ($Year >= (int) $Today->format('Y')) {
                return ['start' => $Start->getTimestamp(), 'end' => $Today->getTimestamp(), 'annualize' => true, 'label' => (string) $Year];
            }
            return ['start' => $Start->getTimestamp(), 'end' => $Start->modify('+1 year')->getTimestamp(), 'annualize' => false, 'label' => (string) $Year];
        }
        switch ($this->ReadPropertyInteger('Basis')) {
            case self::BASIS_LAST_YEAR:
                $Start = $Today->modify('first day of january last year');
                return ['start' => $Start->getTimestamp(), 'end' => $Today->modify('first day of january this year')->getTimestamp(), 'annualize' => false, 'label' => $Start->format('Y')];
            case self::BASIS_THIS_YEAR:
                $Start = $Today->modify('first day of january this year');
                return ['start' => $Start->getTimestamp(), 'end' => $Today->getTimestamp(), 'annualize' => true, 'label' => $Start->format('Y')];
            default:
                return ['start' => $Today->modify('-365 days')->getTimestamp(), 'end' => $Today->getTimestamp(), 'annualize' => false, 'label' => ''];
        }
    }

    /**
     * Lage des Werts auf der Skala von 0 bis 1: vier gleich breite Kategorien, innerhalb der Kategorie nach der Obergrenze verteilt.
     * "Zu hoch" reicht bis zum anderthalbfachen der dritten Grenze.
     *
     * @param array<int, float> $Bounds
     */
    private function position(float $Value, array $Bounds): float
    {
        $Lower = 0.0;
        foreach ($Bounds as $Index => $Limit) {
            if ($Value <= $Limit) {
                return ($Index + ($Limit > $Lower ? ($Value - $Lower) / ($Limit - $Lower) : 1.0)) / 4;
            }
            $Lower = (float) $Limit;
        }
        return (3 + min(1.0, ($Value - $Lower) / ($Lower * 0.5))) / 4;
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        return (string) json_encode([
            'data'  => is_array($Data) ? $Data : null,
            'texts' => [
                'current'     => $this->Translate('Current'),
                'category'    => [
                    $this->Translate('low'),
                    $this->Translate('medium'),
                    $this->Translate('raised'),
                    $this->Translate('too high')
                ],
                'meaning'     => [
                    $this->Translate('Congratulations: it hardly gets any better.'),
                    $this->Translate('The building is average.'),
                    $this->Translate('Every second building uses less.'),
                    $this->Translate('Attention: 90 % of all residential buildings are more efficient than yours.')
                ],
                'perM2'       => $this->Translate('Heating energy per m² and year'),
                'costPerM2'   => $this->Translate('Heating cost per m² and year'),
                'toNext'      => $this->Translate('To the next better category'),
                'toLow'       => $this->Translate('To low'),
                'saving'      => $this->Translate('Saving'),
                'perYear'     => $this->Translate('per year'),
                'upTo'        => $this->Translate('up to'),
                'over'        => $this->Translate('over'),
                'area'        => $this->Translate('Living area'),
                'sizeClass'   => $this->Translate('size class'),
                'sizeFrom'    => $this->Translate('from'),
                'belowRange'  => $this->Translate('Below the smallest size class of the table'),
                'hotWater'    => $this->Translate('incl. hot water with electricity'),
                'annualized'  => $this->Translate('extrapolated from the days so far'),
                'rolling'     => $this->Translate('Last 365 days'),
                'source'      => $this->Translate('Heizspiegel for Germany'),
                'carrier'     => [
                    'gas'      => $this->Translate('Natural gas'),
                    'oil'      => $this->Translate('Heating oil'),
                    'district' => $this->Translate('District heating'),
                    'pellets'  => $this->Translate('Wood pellets'),
                    'electric' => $this->Translate('Heat pump')
                ],
                'consumption' => $this->Translate('Consumption'),
                'needHeating' => $this->Translate('Enter the heating in the Consumption sources instance'),
                'needArea'    => $this->Translate('Enter the living area in the Consumption sources instance'),
                'needCarrier' => $this->Translate('Choose the energy source of the heating in the Consumption sources instance'),
                'needFactor'  => $this->Translate('Enter the energy per unit of the heating in the Consumption sources instance'),
                'noTables'    => $this->Translate('Enter reference values in the instance'),
                'noRow'       => $this->Translate('No reference values for this energy source in the edition'),
                'noSource'    => $this->Translate('The heating variable needs an archive'),
                'empty'       => $this->Translate('No data yet')
            ]
        ]);
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->MaintainVariable('Category', $this->Translate('Category'), VARIABLETYPE_STRING, $Value, 1, true);
        $this->MaintainVariable('KWhPerM2', $this->Translate('Heating energy per m² and year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh/m²', 'DIGITS' => 1], 2, true);
        $this->MaintainVariable('CostPerM2', $this->Translate('Heating cost per m² and year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €/m²', 'DIGITS' => 2], 3, true);
        $this->MaintainVariable('SavingKWh', $this->Translate('Saving potential to the next better category'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0], 4, true);
        $this->MaintainVariable('SavingCost', $this->Translate('Saving potential to the next better category in money'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 0], 5, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 6, true);
        $this->hideVariable('Data');
    }

    private function validate(array $Sources): string
    {
        $Heating = $Sources['Heating'] ?? null;
        if (!is_array($Heating) || (int) ($Heating['ID'] ?? 0) < 1) {
            return 'Heizung fehlt in den Verbrauchsquellen';
        }
        if ($this->tableCarrier((string) ($Heating['Carrier'] ?? '')) === null) {
            return 'Energieträger der Heizung fehlt in den Verbrauchsquellen';
        }
        if ((float) ($Sources['Area'] ?? 0.0) <= 0) {
            return 'Wohnfläche fehlt in den Verbrauchsquellen';
        }
        $Info = $this->unitInfoFor((int) $Heating['ID'], $Sources['Unit']);
        if ($this->kwhPerUnit($Heating, $Info) === null) {
            return 'Heizwert der Heizung fehlt in den Verbrauchsquellen';
        }
        if (!in_array($this->ReadPropertyInteger('Basis'), [self::BASIS_ROLLING, self::BASIS_LAST_YEAR, self::BASIS_THIS_YEAR], true)) {
            return 'Der Zeitraum ist ungültig';
        }
        if ($this->tables() === []) {
            return 'Die Liste der Referenzwerte enthält keine gültige Zeile';
        }
        if ($this->ReadPropertyInteger('UpdateInterval') < 1) {
            return 'Das Aktualisierungsintervall ist ungültig';
        }
        return '';
    }
}
