<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';
require_once __DIR__ . '/../libs/ReferenceTablesStrict.php';

/**
 * Stromspiegel: Ordnet den Jahresstromverbrauch des Haushalts in die Klassen A bis G des Stromspiegels für Deutschland ein.
 * Die Klasse hängt von Personen im Haushalt, Gebäudetyp und elektrischem Warmwasser ab. Die Personenzahl kommt aus den
 * Verbrauchsquellen. Dazu das Einsparpotenzial bis zur nächstbesseren Klasse und bis Klasse A, in kWh und in Geld.
 */
class Stromspiegel extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;
    use ReferenceTables;

    private const BASIS_ROLLING = 0;
    private const BASIS_LAST_YEAR = 1;
    private const BASIS_THIS_YEAR = 2;

    private const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    // Die Vorgabe der Liste in der Instanz stammt aus dem Stromspiegel für Deutschland 2025 (co2online), die Werte lassen sich dort ändern
    private const DEFAULT_YEAR = 2025;

    /**
     * Vorgabe: Obergrenzen der Klassen A bis F in kWh pro Jahr, Klasse G liegt darüber. Gebäudetyp 'flat' = Wohnung, 'house' = Haus,
     * danach Warmwasser 'without' oder 'with' Strom, danach 1 bis 5 Personen (5 steht für fünf und mehr).
     * Quelle: Stromspiegel für Deutschland 2025, co2online (stromspiegel.de).
     */
    private const DEFAULT_TABLE = [
        'house' => [
            'without' => [
                1 => [1100, 1500, 1700, 2000, 2400, 3000],
                2 => [1900, 2200, 2500, 2900, 3300, 4000],
                3 => [2400, 2800, 3200, 3600, 4000, 5000],
                4 => [2600, 3100, 3500, 4000, 4500, 5600],
                5 => [3000, 3600, 4100, 4800, 5500, 7000]
            ],
            'with' => [
                1 => [1300, 1600, 2000, 2300, 2900, 4000],
                2 => [2000, 2500, 3000, 3500, 4000, 5500],
                3 => [2700, 3400, 3900, 4500, 5100, 7000],
                4 => [3000, 3700, 4300, 5000, 6000, 7900],
                5 => [3700, 4600, 5400, 6300, 7400, 10000]
            ]
        ],
        'flat' => [
            'without' => [
                1 => [700, 900, 1100, 1400, 1500, 2000],
                2 => [1200, 1500, 1700, 2000, 2400, 3000],
                3 => [1500, 1900, 2200, 2600, 3000, 3700],
                4 => [1700, 2000, 2500, 2900, 3400, 4100],
                5 => [2000, 2400, 2800, 3500, 4000, 5000]
            ],
            'with' => [
                1 => [900, 1200, 1500, 1700, 2000, 2700],
                2 => [1600, 2000, 2500, 2800, 3200, 4000],
                3 => [2000, 2800, 3300, 3700, 4500, 5500],
                4 => [2400, 3000, 3700, 4200, 4900, 6000],
                5 => [2500, 3700, 4500, 5000, 6500, 8000]
            ]
        ]
    ];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Building', 0);
        $this->RegisterPropertyInteger('Basis', self::BASIS_ROLLING);
        $this->RegisterPropertyString('Excluded', '[]');
        $this->RegisterPropertyString('Tables', (string) json_encode($this->referenceTreeFromFlat($this->defaultRows(self::DEFAULT_YEAR))));
        $this->RegisterPropertyInteger('NewYear', (int) date('Y'));
        $this->RegisterPropertyInteger('UpdateInterval', 720);

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
            $Years = array_keys($this->tables());
            if ($View !== 'current' && !in_array((int) $View, $Years, true)) {
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

    // ---------------------------------------------------------------- Berechnung

    /**
     * Die Referenztabellen aus der Instanz: [Jahr][Gebäudetyp 'flat'|'house'][Warmwasser 'without'|'with'][Personen] => Obergrenzen A bis F.
     * Zeilen mit fehlenden oder nicht aufsteigenden Werten fallen weg.
     *
     * @return array<int, array<string, array<string, array<int, array<int, int>>>>>
     */
    private function tables(): array
    {
        $Tables = [];
        foreach ($this->referenceFlatRows() as $Row) {
            $Bounds = [];
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $Letter) {
                $Bounds[] = (int) ($Row[$Letter] ?? 0);
            }
            $Ascending = $Bounds[0] > 0;
            for ($i = 1; $i < 6; $i++) {
                $Ascending = $Ascending && $Bounds[$i] > $Bounds[$i - 1];
            }
            $Persons = (int) ($Row['Persons'] ?? 0);
            $Year = (int) ($Row['Year'] ?? 0);
            if ($Ascending && $Year >= 2000 && $Persons >= 1 && $Persons <= 5) {
                $Tables[$Year][((int) ($Row['Building'] ?? 0)) === 1 ? 'house' : 'flat'][!empty($Row['HotWater']) ? 'with' : 'without'][$Persons] = $Bounds;
            }
        }
        krsort($Tables);
        return $Tables;
    }

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
        return ['Building' => 0, 'HotWater' => false, 'Persons' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0];
    }

    /**
     * Die Vorgabe als Zeilen der Liste.
     *
     * @return array<int, array<string, mixed>>
     */
    private function defaultRows(int $Year): array
    {
        $Rows = [];
        foreach (self::DEFAULT_TABLE as $Building => $Waters) {
            foreach ($Waters as $Water => $Persons) {
                foreach ($Persons as $Count => $Bounds) {
                    $Rows[] = [
                        'Year'     => $Year,
                        'Building' => $Building === 'house' ? 1 : 0,
                        'HotWater' => $Water === 'with',
                        'Persons'  => $Count,
                        'A'        => $Bounds[0], 'B' => $Bounds[1], 'C' => $Bounds[2], 'D' => $Bounds[3], 'E' => $Bounds[4], 'F' => $Bounds[5]
                    ];
                }
            }
        }
        return $Rows;
    }

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Config = $this->readConfiguration();
        $Sources = $this->requestSources();
        $ArchiveID = $this->getArchiveID();
        if ($Config['NoSources'] || $Sources === null || ($Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return;
        }
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Die Variablen zeigen immer die aktuelle Einordnung, die Kachel zusätzlich das gewählte Jahr
        $Current = $this->evaluateView($Config, $Sources, $ArchiveID, 'current');
        $View = $this->ReadAttributeString('View');
        $Years = array_keys($this->tables());
        $Selected = $View !== 'current' && in_array((int) $View, $Years, true) ? $View : 'current';
        $Shown = $Selected === 'current' ? $Current : $this->evaluateView($Config, $Sources, $ArchiveID, $Selected);

        if (!isset($Current['error'])) {
            $this->SetValue('Class', $Current['letter']);
            $this->SetValue('YearKWh', $Current['yearly']);
            $this->SetValue('PerPerson', $Current['perPerson']);
            $this->SetValue('SavingToA', $Current['toA']);
            $this->SetValue('SavingToACost', $Current['costToA']);
        }
        $this->SetValue('Data', (string) json_encode($Shown + ['years' => $Years, 'selected' => $Selected, 'updated' => time()]));
        $this->UpdateVisualizationValue($this->tileMessage());
    }

    /**
     * Ordnet den Verbrauch ein. 'current' nimmt den eingestellten Zeitraum und die neueste Tabelle, ein Jahr nimmt den Verbrauch dieses
     * Kalenderjahres und die Tabelle desselben Jahres. Bei einem Fehler steht 'error' im Ergebnis.
     */
    private function evaluateView(array $Config, array $Sources, int $ArchiveID, string $View): array
    {
        $Tables = $this->tables();
        if ($Tables === []) {
            return ['error' => 'tables'];
        }
        $TableYear = $View === 'current' ? (int) array_key_first($Tables) : (int) $View;
        $Range = $this->range($View === 'current' ? null : (int) $View);

        $Persons = max(1, min(5, (int) $Config['Residents']));
        $Building = $this->ReadPropertyInteger('Building') === 1 ? 'house' : 'flat';
        $Water = $Config['HotWaterElectric'] ? 'with' : 'without';
        $Bounds = $Tables[$TableYear][$Building][$Water][$Persons] ?? null;
        if ($Bounds === null) {
            return ['error' => 'row', 'tableYear' => $TableYear];
        }

        $Skipped = [];
        $Values = $this->evaluate($Config, $ArchiveID, $Range['start'], $Range['end'], 1, $Skipped);
        $Nodes = $this->buildTree('', $Config, $Values);
        $Listed = array_sum(array_column($Nodes, 'value'));
        $Main = $Config['MainMeter'] > 0 ? ($Values[$Config['MainMeter']] ?? null) : null;
        $Total = $Main ?? $Listed;

        // Gruppen wie Heizung oder Auto zählen nicht zum Haushaltsstrom des Stromspiegels
        $Excluded = $this->excludedValue($Nodes, $this->excludedNames());
        $Consumption = max(0.0, $Total - $Excluded);
        $Days = max(1, (int) round(($Range['end'] - $Range['start']) / 86400));
        $Yearly = $Range['annualize'] ? $Consumption / $Days * 365 : $Consumption;

        $Class = 6;
        foreach ($Bounds as $Index => $Limit) {
            if ($Yearly <= $Limit) {
                $Class = $Index;
                break;
            }
        }
        $Price = (float) ($Sources['PriceNow'] ?? $Config['FixedPrice']);
        $ToA = max(0.0, $Yearly - $Bounds[0]);
        $ToNext = $Class > 0 ? max(0.0, $Yearly - $Bounds[$Class - 1]) : 0.0;

        return [
            'class'      => $Class,
            'letter'     => self::LETTERS[$Class],
            'yearly'     => round($Yearly, 0),
            'perPerson'  => round($Yearly / max(1, (int) $Config['Residents']), 0),
            'residents'  => (int) $Config['Residents'],
            'persons'    => $Persons,
            'bounds'     => $Bounds,
            'building'   => $this->ReadPropertyInteger('Building'),
            'hotWater'   => $Config['HotWaterElectric'],
            'tableYear'  => $TableYear,
            'basisLabel' => $Range['label'],
            'annualized' => $Range['annualize'],
            'days'       => $Days,
            'excluded'   => round($Excluded, 0),
            'toNext'     => round($ToNext, 0),
            'toA'        => round($ToA, 0),
            'cost'       => round($Yearly * $Price, 0),
            'costToNext' => round($ToNext * $Price, 0),
            'costToA'    => round($ToA * $Price, 0),
            'price'      => $Price,
            'position'   => round($this->position($Yearly, $Bounds), 4)
        ];
    }

    /**
     * Zeitraum der Auswertung. Mit Jahr: dieses Kalenderjahr, das laufende wird hochgerechnet. Ohne Jahr der eingestellte Zeitraum.
     */
    private function range(?int $Year): array
    {
        $Today = new DateTimeImmutable('today');
        if ($Year !== null) {
            $Start = (new DateTimeImmutable('today'))->setDate($Year, 1, 1);
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
     * Lage des Werts auf der Skala von 0 bis 1: sieben gleich breite Klassen, innerhalb der Klasse nach der Obergrenze verteilt.
     * Klasse G reicht bis zum anderthalbfachen der Obergrenze von F.
     */
    private function position(float $Value, array $Bounds): float
    {
        $Lower = 0.0;
        foreach ($Bounds as $Index => $Limit) {
            if ($Value <= $Limit) {
                return ($Index + ($Limit > $Lower ? ($Value - $Lower) / ($Limit - $Lower) : 1.0)) / 7;
            }
            $Lower = (float) $Limit;
        }
        return (6 + min(1.0, ($Value - $Lower) / ($Lower * 0.5))) / 7;
    }

    /**
     * @return array<int, string>
     */
    private function excludedNames(): array
    {
        $Rows = json_decode($this->ReadPropertyString('Excluded'), true);
        $Names = array_map(fn ($Row): string => mb_strtolower(trim((string) (is_array($Row) ? ($Row['Name'] ?? '') : ''))), is_array($Rows) ? $Rows : []);
        return array_values(array_filter($Names, fn (string $Name): bool => $Name !== ''));
    }

    /**
     * Die Auswahl der ausgeschlossenen Gruppen und Geräte bietet die Namen aus den Verbrauchsquellen an. Bereits gewählte Namen, die es
     * dort nicht mehr gibt, bleiben in der Liste auswählbar, damit sie nicht stillschweigend verschwinden.
     */
    private function extendConfigurationForm(array &$Form): void
    {
        $this->overrideVisibility($Form);
        $Names = [];
        $Config = $this->readConfiguration();
        foreach ($Config['Groups'] as $Group) {
            $Names[$Group['Name']] = true;
        }
        foreach ($Config['Consumers'] as $Consumer) {
            $Names[$Consumer['Name']] = true;
        }
        $Rows = json_decode($this->ReadPropertyString('Excluded'), true);
        foreach (is_array($Rows) ? $Rows : [] as $Row) {
            if (is_array($Row) && trim((string) ($Row['Name'] ?? '')) !== '') {
                $Names[trim((string) $Row['Name'])] = true;
            }
        }
        $Options = [];
        foreach (array_keys($Names) as $Name) {
            $Options[] = ['caption' => (string) $Name, 'value' => (string) $Name];
        }
        $Fill = function (array &$Items) use (&$Fill, $Options): void
        {
            foreach ($Items as &$Item) {
                if (($Item['name'] ?? '') === 'Excluded') {
                    foreach ($Item['columns'] as &$Column) {
                        if ($Column['name'] === 'Name') {
                            $Column['edit']['options'] = $Options;
                        }
                    }
                }
                if (isset($Item['items']) && is_array($Item['items'])) {
                    $Fill($Item['items']);
                }
            }
        };
        $Fill($Form['elements']);
    }

    /**
     * Summe der Gruppen und Geräte, die ausgeschlossen sind. Eine Gruppe zählt mit allen Mitgliedern, darin liegende Treffer nicht doppelt.
     */
    private function excludedValue(array $Nodes, array $Names): float
    {
        $Sum = 0.0;
        foreach ($Nodes as $Node) {
            if ($Node['type'] !== 'rest' && in_array(mb_strtolower($Node['name']), $Names, true)) {
                $Sum += $Node['value'];
            } else {
                $Sum += $this->excludedValue($Node['children'], $Names);
            }
        }
        return $Sum;
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        return (string) json_encode([
            'data'  => is_array($Data) ? $Data : null,
            'texts' => [
                'class'        => $this->Translate('Class'),
                'perYear'      => $this->Translate('per year'),
                'perPerson'    => $this->Translate('per person'),
                'toNext'       => $this->Translate('To the next better class'),
                'toA'          => $this->Translate('To class A'),
                'cost'         => $this->Translate('Cost per year'),
                'saving'       => $this->Translate('Saving'),
                'upTo'         => $this->Translate('up to'),
                'over'         => $this->Translate('over'),
                'persons'      => $this->Translate('persons'),
                'person'       => $this->Translate('person'),
                'flat'         => $this->Translate('Apartment'),
                'house'        => $this->Translate('House'),
                'waterWith'    => $this->Translate('hot water with electricity'),
                'waterWithout' => $this->Translate('hot water without electricity'),
                'excluded'     => $this->Translate('not counted'),
                'annualized'   => $this->Translate('extrapolated from the days so far'),
                'rolling'      => $this->Translate('Last 365 days'),
                'meaning'      => [
                    $this->Translate('low'),
                    $this->Translate('low'),
                    $this->Translate('average'),
                    $this->Translate('average'),
                    $this->Translate('high'),
                    $this->Translate('high'),
                    $this->Translate('very high')
                ],
                'meaningText' => [
                    $this->Translate('Congratulations, you use much less electricity than comparable households.'),
                    $this->Translate('You need less electricity than comparable households. You can still save.'),
                    $this->Translate('Your consumption is average or slightly below. Use all the possibilities to save electricity.'),
                    $this->Translate('Your consumption is average or slightly below. Use all the possibilities to save electricity.'),
                    $this->Translate('You use more electricity than every second comparable household. Saving pays off especially for you.'),
                    $this->Translate('You use more electricity than every second comparable household. Saving pays off especially for you.'),
                    $this->Translate('You should act urgently. You use more electricity than 85 % of all comparable households.')
                ],
                'meaningTitle'  => $this->Translate('Meaning'),
                'source'        => $this->Translate('Stromspiegel for Germany'),
                'current'       => $this->Translate('Current'),
                'editionNote'   => $this->Translate('Reference values of the edition'),
                'noTables'      => $this->Translate('Enter reference values in the instance'),
                'noRow'         => $this->Translate('No reference values for this household in the edition'),
                'needResidents' => $this->Translate('Enter the number of residents in the Consumption sources instance'),
                'needEnergy'    => $this->Translate('The Consumption sources must count in kWh'),
                'empty'         => $this->Translate('No data yet')
            ]
        ]);
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->MaintainVariable('Class', $this->Translate('Class'), VARIABLETYPE_STRING, $Value, 1, true);
        $this->MaintainVariable('YearKWh', $this->Translate('Consumption per year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0], 2, true);
        $this->MaintainVariable('PerPerson', $this->Translate('Consumption per person'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0], 3, true);
        $this->MaintainVariable('SavingToA', $this->Translate('Saving potential to class A'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0], 4, true);
        $this->MaintainVariable('SavingToACost', $this->Translate('Saving potential to class A in money'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 0], 5, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 6, true);
        $this->hideVariable('Data');
    }

    private function validate(array $Sources): string
    {
        if ((int) ($Sources['Residents'] ?? 0) < 1) {
            return 'Die Zahl der Bewohner fehlt in den Verbrauchsquellen';
        }
        if (($Sources['Unit']['Family'] ?? '') !== 'energy') {
            return 'Die Verbrauchsquellen rechnen nicht in Energie';
        }
        if (!in_array($this->ReadPropertyInteger('Building'), [0, 1], true)) {
            return 'Der Gebäudetyp ist ungültig';
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
