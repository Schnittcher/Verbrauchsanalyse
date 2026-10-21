<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/EmissionFactorsStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';
require_once __DIR__ . '/../libs/CustomPeriodStrict.php';

/**
 * CO2Bilanz: Der CO2-Ausstoß aus dem Verbrauch der Verbrauchsquellen, entweder für den Haushalt (Verbraucher und Gruppen) oder für die Heizung. Der Emissionsfaktor ist fest (Voreinstellung oder eigener
 * Wert) oder kommt aus einer Variable mit dem aktuellen Faktor des Netzes, dann zählt jede Stunde mit ihrem Faktor. Zeigt Summe,
 * Vergleich mit der Vorperiode, Anteile je Gruppe und Gerät, Ausstoß je Bewohner und eine Baum-Entsprechung.
 */
class CO2Bilanz extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use EmissionFactors;
    use ConsumptionTree;
    use CustomPeriod;

    // Wofür die Instanz rechnet: für den Haushalt (Verbraucher und Gruppen der Verbrauchsquellen) oder für die Heizung. Für beides legt man zwei Instanzen an.
    private const SCOPE_HOUSEHOLD = 0;
    private const SCOPE_HEATING = 1;

    private const PERIOD_TODAY = 0;
    private const PERIOD_WEEK = 1;
    private const PERIOD_MONTH = 2;
    private const PERIOD_YEAR = 3;
    private const PERIOD_CUSTOM = 4;

    private const HEATING_COLOR = '#E07A5F';
    private const PALETTE = ['#12B69C', '#E08A1E', '#6366F1', '#D64545', '#0EA5E9', '#B45309', '#8B5CF6', '#64748B'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Scope', self::SCOPE_HOUSEHOLD);
        $this->RegisterPropertyFloat('KgPerTree', 12.5);
        $this->RegisterPropertyInteger('Period', self::PERIOD_MONTH);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterAttributeString('LastRequest', '');
        $this->RegisterTimer('Refresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Refresh\', \'\');');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 steht für HTML der Wert 1 aus der Doku.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    /**
     * Das Formular zeigt nur die Felder zum gewählten Bereich: Faktor und Faktor-Variable für Strom, Faktor der Heizung für die Heizung.
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Visible = $this->scopeVisibility($this->ReadPropertyInteger('Scope'));
        $Apply = function (array &$Items) use (&$Apply, $Visible): void
        {
            foreach ($Items as &$Item) {
                if (isset($Item['name'], $Visible[$Item['name']])) {
                    $Item['visible'] = $Visible[$Item['name']];
                }
                if (isset($Item['items']) && is_array($Item['items'])) {
                    $Apply($Item['items']);
                }
            }
        };
        $Apply($Form['elements']);
        $this->overrideVisibility($Form);
        $Apply2 = function (array &$Items) use (&$Apply2, $Visible): void
        {
            foreach ($Items as &$Item) {
                $Name = (string) ($Item['name'] ?? '');
                if (isset($Visible[$Name]) && str_starts_with($Name, 'Override')) {
                    $Item['visible'] = $Visible[$Name] && $this->ReadPropertyBoolean('OverrideSources');
                }
                if (isset($Item['items']) && is_array($Item['items'])) {
                    $Apply2($Item['items']);
                }
            }
        };
        $Apply2($Form['elements']);
        return (string) json_encode($Form);
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage('')) . ');</script>';
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

        if ($this->ReadPropertyInteger('Scope') === self::SCOPE_HOUSEHOLD && $this->mediumEmpty($Sources)) {
            $this->SendDebug(__FUNCTION__, 'Das gewählte Medium ist nicht eingerichtet', 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(IS_INACTIVE);
            $this->SetValue('Data', '');
            $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The selected medium is not set up in the Consumption sources')));
            return;
        }

        $Error = $this->validate();
        if ($Error !== '') {
            $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            $this->SetValue('Data', (string) json_encode(['error' => $Error]));
            $this->UpdateVisualizationValue($this->tileMessage(''));
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
        if ($Ident === 'FormScope') {
            foreach ($this->scopeVisibility((int) $Value) as $Field => $Visible) {
                $this->UpdateFormField($Field, 'visible', str_starts_with($Field, 'Override') ? ($Visible && $this->ReadPropertyBoolean('OverrideSources')) : $Visible);
            }
            return;
        }
        if ($Ident === 'Load') {
            $Request = $this->parseRequest($Value);
            if ($Request === null) {
                $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The period is invalid')));
                return;
            }
            $this->WriteAttributeString('LastRequest', (string) json_encode($Request));
            $this->refresh();
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Berechnet die Bilanz neu, setzt die Variablen und schickt die Kachel.
     */
    public function Update(): void
    {
        $this->refresh();
    }

    /**
     * @return array<string, bool>
     */
    private function scopeVisibility(int $Scope): array
    {
        $Household = $Scope !== self::SCOPE_HEATING;
        return [
            'Medium'                  => $Household,
            'OverrideEmission'        => $Household,
            'OverrideEmissionID'      => $Household,
            'OverrideHeatingEnergy'   => !$Household,
            'OverrideHeatingEmission' => !$Household
        ];
    }

    // ---------------------------------------------------------------- Berechnung

    private function parseRequest(mixed $Value): ?array
    {
        if (is_string($Value) && str_starts_with($Value, '{')) {
            $Custom = $this->parseCustom($Value);
            return $Custom === null ? null : ['period' => self::PERIOD_CUSTOM, 'custom' => $Custom];
        }
        $Period = (int) $Value;
        return ($Period >= self::PERIOD_TODAY && $Period <= self::PERIOD_YEAR) ? ['period' => $Period, 'custom' => null] : null;
    }

    private function currentRequest(): array
    {
        $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
        if (is_array($Last) && isset($Last['period'])) {
            $Period = (int) $Last['period'];
            if ($Period === self::PERIOD_CUSTOM && is_array($Last['custom'] ?? null)) {
                return ['period' => $Period, 'custom' => $Last['custom']];
            }
            if ($Period >= self::PERIOD_TODAY && $Period <= self::PERIOD_YEAR) {
                return ['period' => $Period, 'custom' => null];
            }
        }
        return ['period' => $this->ReadPropertyInteger('Period'), 'custom' => null];
    }

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Config = $this->readConfiguration();
        $ArchiveID = $this->getArchiveID();
        // Die Heizung braucht keine Verbraucher im Medium Strom
        if ($Config['NoSources'] || ($this->ReadPropertyInteger('Scope') === self::SCOPE_HOUSEHOLD && $Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return;
        }
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Variablen: immer die vier festen Zeiträume bis jetzt
        foreach ([self::PERIOD_TODAY => 'Today', self::PERIOD_WEEK => 'Week', self::PERIOD_MONTH => 'Month', self::PERIOD_YEAR => 'Year'] as $Period => $Name) {
            $Data = $this->build($Config, $ArchiveID, $Period, null, false);
            $this->SetValue($Name, $Data === null ? 0.0 : $Data['total']);
            if ($Period === self::PERIOD_YEAR && $Data !== null) {
                if ($Config['Residents'] > 0) {
                    $this->SetValue('PerResident', round($Data['total'] / $Config['Residents'], 1));
                }
                $this->SetValue('Trees', round($Data['total'] / max(0.1, $this->ReadPropertyFloat('KgPerTree')), 1));
            }
        }

        $Request = $this->currentRequest();
        $Data = $this->build($Config, $ArchiveID, $Request['period'], $Request['custom'], true);
        if ($Data !== null) {
            $this->SetValue('Data', (string) json_encode($Data));
        }
        $this->UpdateVisualizationValue($this->tileMessage(''));
    }

    /**
     * Beginn, Ende (ausschließlich) und Bezeichnung des Zeitraums sowie der entsprechende Zeitraum davor. Die festen Zeiträume laufen
     * bis jetzt, der Vergleich ist der gleich lange Abschnitt der Vorperiode (gestern bis zur selben Uhrzeit, Vorwoche und so weiter).
     */
    private function range(int $Period, ?array $Custom): array
    {
        $Today = new DateTimeImmutable('today');
        $Now = time();
        switch ($Period) {
            case self::PERIOD_WEEK:
                $Start = $Today->modify('monday this week');
                $Previous = $Start->modify('-7 days');
                $Label = sprintf($this->Translate('Week %s'), $Start->format('W/o'));
                break;
            case self::PERIOD_MONTH:
                $Start = $Today->modify('first day of this month');
                $Previous = $Start->modify('-1 month');
                $Label = $Start->format('m/Y');
                break;
            case self::PERIOD_YEAR:
                $Start = $Today->modify('first day of january this year');
                $Previous = $Start->modify('-1 year');
                $Label = $Start->format('Y');
                break;
            case self::PERIOD_CUSTOM:
                $Start = $Today->setTimestamp((int) ($Custom['start'] ?? $Today->getTimestamp()));
                $End = min((int) ($Custom['end'] ?? $Now), $Now);
                $Length = max(1, $End - $Start->getTimestamp());
                return [
                    'start'     => $Start->getTimestamp(),
                    'end'       => $End,
                    'prevStart' => $Start->getTimestamp() - $Length,
                    'prevEnd'   => $Start->getTimestamp(),
                    'label'     => date('d.m.Y', $Start->getTimestamp()) . ' - ' . date('d.m.Y', (int) ($Custom['end'] ?? $Now) - 1)
                ];
            default:
                $Start = $Today;
                $Previous = $Start->modify('-1 day');
                $Label = $Start->format('d.m.Y');
                break;
        }
        $Length = $Now - $Start->getTimestamp();
        return [
            'start'     => $Start->getTimestamp(),
            'end'       => $Now,
            'prevStart' => $Previous->getTimestamp(),
            'prevEnd'   => min($Previous->getTimestamp() + $Length, $Start->getTimestamp()),
            'label'     => $Label
        ];
    }

    /**
     * Emissionsfaktor in g je Basiseinheit (kWh oder m³) des Mediums aus den Verbrauchsquellen.
     */
    private function fixedFactor(array $Config): float
    {
        return (float) $Config['EmissionFactor'];
    }

    /**
     * Bilanz für einen Zeitraum. Mit $Detailed entstehen auch Zeilen, Vergleich und Kennzahlen für die Kachel.
     */
    private function build(array $Config, int $ArchiveID, int $Period, ?array $Custom, bool $Detailed): ?array
    {
        $Range = $this->range($Period, $Custom);
        $Totals = $this->totals($Config, $ArchiveID, $Range['start'], $Range['end']);
        if ($Totals === null) {
            return null;
        }
        $Result = ['total' => round($Totals['total'], 2)];
        if (!$Detailed) {
            return $Result;
        }
        $Previous = $this->totals($Config, $ArchiveID, $Range['prevStart'], $Range['prevEnd']);
        $PreviousTotal = $Previous['total'] ?? 0.0;

        $Rows = [];
        $this->rows($Totals['nodes'], 0, $Totals['total'], $Rows);
        $Listed = array_sum(array_column($Totals['nodes'], 'value'));
        if ($Config['MainMeter'] > 0 && $Totals['electric'] - $Listed > 0.0005) {
            $Rest = $Totals['electric'] - $Listed;
            $Rows[] = ['level' => 0, 'type' => 'rest', 'name' => $this->Translate('Not recorded'), 'value' => round($Rest, 2), 'share' => $Totals['total'] > 0 ? round($Rest / $Totals['total'] * 100, 1) : 0.0, 'color' => '#94A3B8'];
        }
        if ($Totals['heating'] !== null && $Totals['heating']['kg'] > 0) {
            $Rows[] = ['level' => 0, 'type' => 'heating', 'name' => $Totals['heating']['name'], 'value' => round($Totals['heating']['kg'], 2), 'share' => $Totals['total'] > 0 ? round($Totals['heating']['kg'] / $Totals['total'] * 100, 1) : 0.0, 'color' => self::HEATING_COLOR];
        }

        return $Result + [
            'label'       => $Range['label'],
            'period'      => $Period,
            'previous'    => $PreviousTotal > 0 ? round($PreviousTotal, 2) : null,
            'change'      => $PreviousTotal > 0 ? round(($Totals['total'] - $PreviousTotal) / $PreviousTotal * 100, 1) : null,
            'perResident' => $Config['Residents'] > 0 ? round($Totals['total'] / $Config['Residents'], 2) : null,
            'trees'       => round($Totals['total'] / max(0.1, $this->ReadPropertyFloat('KgPerTree')), 2),
            'consumption' => round($Totals['consumed'], 2),
            'consUnit'    => $Config['Unit']['Out'],
            'avgFactor'   => $Totals['consumed'] > 0 ? round($Totals['total'] / $Totals['consumed'] * 1000, 0) : null,
            'variable'    => $Totals['variable'],
            'rows'        => $Rows,
            'updated'     => time()
        ];
    }

    /**
     * CO2 aller Quellen im Bereich: Summe in kg, Baum aus Gruppen und Geräten und der verbrauchte Wert in der Basiseinheit.
     */
    private function totals(array $Config, int $ArchiveID, int $Start, int $End): ?array
    {
        if ($End <= $Start) {
            return ['total' => 0.0, 'electric' => 0.0, 'nodes' => [], 'consumed' => 0.0, 'variable' => false, 'heating' => null];
        }
        $Level = ($End - $Start) <= 2 * 86400 ? 0 : 1;
        $Factors = $this->factorHours($Config, $ArchiveID, $Start, $End) ?? [];
        $Fixed = $this->fixedFactor($Config);
        $Household = $this->ReadPropertyInteger('Scope') === self::SCOPE_HOUSEHOLD;
        $IDs = [];
        if ($Household) {
            $IDs = array_keys($Config['Consumers']);
            if ($Config['MainMeter'] > 0) {
                $IDs[] = $Config['MainMeter'];
            }
            foreach ($Config['Groups'] as $Group) {
                if ($Group['MeterID'] > 0) {
                    $IDs[] = $Group['MeterID'];
                }
            }
        }
        $Values = [];
        $Used = [];
        foreach (array_unique($IDs) as $ID) {
            $Result = $this->co2Of($Config, $ArchiveID, (int) $ID, $Start, $End, $Level, $Factors, $Fixed);
            $Values[$ID] = $Result === null ? null : $Result['kg'];
            $Used[$ID] = $Result === null ? 0.0 : $Result['consumed'];
        }
        $Nodes = $this->buildTree('', $Config, $Values);
        $Listed = array_sum(array_column($Nodes, 'value'));
        $Main = $Config['MainMeter'] > 0 ? ($Values[$Config['MainMeter']] ?? null) : null;
        // Verbrauch in der Basiseinheit: der Hauptzähler, ohne ihn die Geräte der obersten Ebene
        $Consumed = 0.0;
        if ($Config['MainMeter'] > 0) {
            $Consumed = $Used[$Config['MainMeter']] ?? 0.0;
        } else {
            foreach ($Config['Consumers'] as $ID => $Consumer) {
                if ($Consumer['Group'] === '') {
                    $Consumed += $Used[$ID] ?? 0.0;
                }
            }
        }
        $Electric = $Main ?? $Listed;
        // Bei der Heizung zählt nur sie, der Strom einer Wärmepumpe steckt im Stromzähler und gehört zur Instanz für den Haushalt.
        $Heating = $Household ? null : $this->heatingCo2($Config, $ArchiveID, $Start, $End, $Level);
        return [
            'total'    => $Electric + ($Heating['kg'] ?? 0.0),
            'electric' => $Electric,
            'nodes'    => $Nodes,
            'consumed' => $Consumed + ($Heating['kWh'] ?? 0.0),
            'variable' => $Factors !== [],
            'heating'  => $Heating
        ];
    }

    /**
     * CO2 der Heizung aus den Verbrauchsquellen: Verbrauch der Heizungsvariable in kWh (mit dem Heizwert aus dem Block, bei Erdgas und
     * Heizöl ohne Heizwert mit etwa 10 kWh je Einheit) mal Faktor. Der Faktor ist der eigene Wert der Instanz, sonst der des Energieträgers.
     * Strom (Wärmepumpe) zählt nicht, er steckt im Stromzähler. Null ohne Heizung, ohne Faktor oder ohne Archiv.
     *
     * @return array{kg: float, kWh: float, name: string}|null
     */
    private function heatingCo2(array $Config, int $ArchiveID, int $Start, int $End, int $Level): ?array
    {
        $Sources = $this->requestSources();
        $Heating = is_array($Sources) ? ($Sources['Heating'] ?? null) : null;
        if (!is_array($Heating) || (int) ($Heating['ID'] ?? 0) < 1 || ($Heating['Carrier'] ?? '') === 'electric') {
            return null;
        }
        $Factor = $this->heatingFactor($Heating);
        $ID = (int) $Heating['ID'];
        if ($Factor <= 0 || !IPS_VariableExists($ID) || !AC_GetLoggingStatus($ArchiveID, $ID)) {
            return null;
        }
        $Info = $this->unitInfoFor($ID, $Config['Unit']);
        $PerUnit = $this->heatingEnergyPerUnit($Heating, $Info);
        if ($PerUnit === null) {
            return null;
        }
        $KWh = $this->consumptionBetween($ArchiveID, $ID, $Info, $Start, $End, $Level) * $PerUnit;
        return ['kg' => $KWh * $Factor / 1000, 'kWh' => $KWh, 'name' => trim((string) ($Heating['Name'] ?? '')) !== '' ? (string) $Heating['Name'] : $this->Translate('Heating')];
    }

    private function rows(array $Nodes, int $Level, float $Total, array &$Rows): void
    {
        foreach ($Nodes as $Index => $Node) {
            $Rows[] = [
                'level' => $Level,
                'type'  => $Node['type'],
                'name'  => $Node['name'],
                'value' => round($Node['value'], 2),
                'share' => $Total > 0 ? round($Node['value'] / $Total * 100, 1) : 0.0,
                'color' => $Level === 0 ? (($Node['color'] ?? '') !== '' ? $Node['color'] : self::PALETTE[$Index % count(self::PALETTE)]) : ''
            ];
            $this->rows($Node['children'], $Level + 1, $Total, $Rows);
        }
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(string $Message): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        $Request = $this->currentRequest();
        $Config = $this->readConfiguration();
        return (string) json_encode([
            'data'    => is_array($Data) ? $Data : null,
            'period'  => $Request['period'],
            'today'   => date('Y-m-d'),
            'message' => $Message,
            'factor'  => ($Config['EmissionID'] ?? 0) > 0 ? 0 : ($Config['EmissionFactor'] ?? 0),
            'texts'   => [
                'today'        => $this->Translate('Today'),
                'week'         => $this->Translate('Week'),
                'month'        => $this->Translate('Month'),
                'year'         => $this->Translate('Year'),
                'custom'       => $this->Translate('Custom'),
                'from'         => $this->Translate('From'),
                'to'           => $this->Translate('To'),
                'show'         => $this->Translate('Show'),
                'total'        => $this->Translate('CO2 emissions'),
                'previous'     => $this->Translate('To the previous period'),
                'perResident'  => $this->Translate('Per resident'),
                'trees'        => $this->Translate('Trees to absorb it in a year'),
                'factor'       => $this->Translate('Average emission factor'),
                'variableNote' => $this->Translate('hourly factor of the grid'),
                'distribution' => $this->Translate('Distribution'),
                'of'           => $this->Translate('of the total'),
                'consumption'  => $this->Translate('Consumption'),
                'errors'       => [
                    'heating'  => $this->Translate('Enter the heating in the Consumption sources instance'),
                    'electric' => $this->Translate('The heating runs on electricity, its electricity is counted in the household instance'),
                    'factor'   => $this->Translate('Enter the emission factor in the Consumption sources instance'),
                    'unit'     => $this->Translate('Enter the energy per unit of the heating in the Consumption sources instance')
                ],
                'empty'       => $this->Translate('No data yet')
            ]
        ]);
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Kg = $Value + ['SUFFIX' => ' kg', 'DIGITS' => 1];
        $this->MaintainVariable('Today', $this->Translate('CO2 today'), VARIABLETYPE_FLOAT, $Kg, 1, true);
        $this->MaintainVariable('Week', $this->Translate('CO2 this week'), VARIABLETYPE_FLOAT, $Kg, 2, true);
        $this->MaintainVariable('Month', $this->Translate('CO2 this month'), VARIABLETYPE_FLOAT, $Kg, 3, true);
        $this->MaintainVariable('Year', $this->Translate('CO2 this year'), VARIABLETYPE_FLOAT, $Kg, 4, true);
        $this->MaintainVariable('PerResident', $this->Translate('CO2 per resident this year'), VARIABLETYPE_FLOAT, $Kg, 5, true);
        $this->MaintainVariable('Trees', $this->Translate('Trees to absorb it in a year'), VARIABLETYPE_FLOAT, $Value + ['DIGITS' => 1], 6, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 7, true);
        $this->hideVariable('Data');
    }

    private function validate(): string
    {
        if ($this->ReadPropertyInteger('Scope') === self::SCOPE_HOUSEHOLD) {
            $Sources = $this->requestSources();
            if (is_array($Sources) && (float) ($Sources['EmissionFactor'] ?? 0) <= 0 && (int) ($Sources['EmissionID'] ?? 0) <= 0) {
                return 'factor';
            }
        }
        if ($this->ReadPropertyInteger('Scope') === self::SCOPE_HEATING) {
            $Sources = $this->requestSources();
            $Heating = is_array($Sources) ? ($Sources['Heating'] ?? null) : null;
            if (!is_array($Heating) || (int) ($Heating['ID'] ?? 0) < 1) {
                return 'heating';
            }
            if (($Heating['Carrier'] ?? '') === 'electric') {
                return 'electric';
            }
            if ($this->heatingFactor($Heating) <= 0) {
                return 'factor';
            }
            $Info = $this->unitInfoFor((int) $Heating['ID'], $Sources['Unit']);
            if ($Info['Family'] !== 'energy' && (float) ($Heating['EnergyPerUnit'] ?? 0) <= 0 && !in_array($Heating['Carrier'], ['gas', 'oil'], true)) {
                return 'unit';
            }
        }
        if ($this->ReadPropertyFloat('KgPerTree') <= 0) {
            return 'Die Bindung je Baum muss größer als 0 sein';
        }
        if ($this->ReadPropertyInteger('Period') < self::PERIOD_TODAY || $this->ReadPropertyInteger('Period') > self::PERIOD_YEAR) {
            return 'Der Zeitraum ist ungültig';
        }
        if ($this->ReadPropertyInteger('UpdateInterval') < 1) {
            return 'Das Aktualisierungsintervall ist ungültig';
        }
        return '';
    }
}
