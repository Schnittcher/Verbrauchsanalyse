<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

class Grundlast extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('OverrideSourceID', 0);
        $this->RegisterPropertyBoolean('RankConsumers', true);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('AbsenceVariableID', 0);
        $this->RegisterPropertyBoolean('AbsenceInverted', false);
        $this->RegisterPropertyInteger('NightStart', 1);
        $this->RegisterPropertyInteger('NightEnd', 5);
        $this->RegisterPropertyInteger('Days', 14);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterTimer('Calculate', 0, 'GRL_Calculate($_IPS[\'TARGET\']);');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 fehlt auch INSTANCE_VISUALIZATION_TYPE_HTML.
        // Deshalb steht für HTML der Wert 1 aus der Doku. Der Vollbildtyp (2) braucht 9.1, sonst bleibt die maximierte Ansicht leer.
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
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage($this->GetValue('Data'))) . ');</script>';
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

        $this->maintainVariables();

        $Sources = $this->requestSources();
        if ($Sources === null) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Splitter Verbrauchsquellen verbunden', 0);
            $this->SetTimerInterval('Calculate', 0);
            $this->SetStatus(self::STATUS_NO_SOURCES);
            return;
        }

        $Error = $this->validate($Sources);
        if ($Error !== '') {
            $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            $this->SetTimerInterval('Calculate', 0);
            $this->SetStatus($this->sourceID($Sources) === 0 ? IS_INACTIVE : self::STATUS_INVALID_CONFIGURATION);
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
        if (!$this->handleSourceAction($Ident, $Value)) {
            $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
        }
    }

    /**
     * Ermittelt die Grundlast aus den Nächten der letzten Tage und schreibt die Variablen.
     */
    public function Calculate(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $Sources = $this->requestSources();
        if ($Sources === null || $this->validate($Sources) !== '') {
            return;
        }

        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $Result = $this->analyse($ArchiveID, $this->sourceID($Sources), $Sources['Unit']);
        if ($Result === null) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        $Price = (float) $Sources['PriceNow'];
        $YearKWh = $Result['Median'] / 1000 * 8760;
        $this->SetValue('BaseLoad', round($Result['Median'], 1));
        $this->SetValue('LastNight', round($Result['Last'], 1));
        $this->SetValue('YearKWh', round($YearKWh, 0));
        $this->SetValue('YearCost', round($YearKWh * $Price, 2));
        $this->SetValue('Share', $Result['DailyKWh'] > 0 ? round($Result['Median'] * 24 / 1000 / $Result['DailyKWh'] * 100, 1) : 0.0);

        $Ranking = [];
        $NoData = [];
        if ($this->ReadPropertyBoolean('RankConsumers')) {
            foreach ($Sources['Consumers'] as $Consumer) {
                $Load = $this->analyse($ArchiveID, (int) $Consumer['ID'], $Sources['Unit']);
                if ($Load !== null) {
                    $Ranking[$Consumer['Name']] = $Load['Median'];
                } else {
                    $NoData[] = (string) $Consumer['Name'];
                }
            }
        }
        arsort($Ranking);
        $Text = [];
        $Entries = [];
        foreach ($Ranking as $Name => $Watt) {
            $Text[] = $Name . ' ' . number_format($Watt, 1, '.', '') . ' W';
            $Entries[] = ['name' => (string) $Name, 'watt' => round($Watt, 1)];
        }
        // Verbraucher ohne nutzbare Nachtdaten (kein Archiv, keine Energieeinheit, zu wenig Verlauf) stehen grau am Ende
        foreach ($NoData as $Name) {
            $Entries[] = ['name' => $Name, 'watt' => null];
        }
        $this->SetValue('Standby', implode('; ', $Text));

        // Verbrauch bei Abwesenheit, nur mit Abwesenheitsvariable
        $Absence = $this->absence($ArchiveID, $Result['Series'], $Result['Median']);
        if ($this->ReadPropertyInteger('AbsenceVariableID') > 0) {
            $this->SetValue('AbsenceLoad', $Absence !== null ? round($Absence['Load'], 1) : 0.0);
            $this->SetValue('AbsenceExtra', $Absence !== null ? round($Absence['Extra'], 1) : 0.0);
            $this->SetValue('AbsenceHours', $Absence !== null ? $Absence['Hours'] : 0);
        }

        $Nights = [];
        foreach ($Result['Nights'] as $Day => $Watt) {
            $Nights[] = ['day' => $Day, 'watt' => round($Watt, 1)];
        }
        $Json = (string) json_encode([
            'baseLoad'  => round($Result['Median'], 1),
            'lastNight' => round($Result['Last'], 1),
            'yearKWh'   => round($YearKWh, 0),
            'yearCost'  => round($YearKWh * $Price, 2),
            'share'     => $this->GetValue('Share'),
            'nights'    => $Nights,
            'ranking'   => $Entries,
            'sourceId'  => $this->sourceID($Sources),
            'clickOpen' => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'absence'   => $Absence === null ? null : ['load' => round($Absence['Load'], 1), 'extra' => round($Absence['Extra'], 1), 'hours' => $Absence['Hours']]
        ]);
        $this->SetValue('Data', $Json);
        $this->UpdateVisualizationValue($this->tileMessage($Json));

        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Grundlast einer Variable: Mittlere Leistung im Nachtfenster je Nacht, davon der Median über die Tage.
     * Der Median bleibt stabil, wenn in einzelnen Nächten etwas lief.
     * Null, wenn die Variable nicht nutzbar ist (kein Archiv, keine Energieeinheit, keine Zähler-Aggregation) oder keine Nacht vollständig ist.
     */
    private function analyse(int $ArchiveID, int $ID, array $Fallback): ?array
    {
        if (!IPS_VariableExists($ID) || !AC_GetLoggingStatus($ArchiveID, $ID)) {
            return null;
        }
        $Info = $this->unitInfoFor($ID, $Fallback);
        if ($Info['Family'] !== 'energy') {
            return null;
        }

        $End = (new DateTimeImmutable('today'))->getTimestamp();
        $Days = max(1, $this->ReadPropertyInteger('Days'));
        $Start = (new DateTimeImmutable('today'))->modify('-' . $Days . ' days')->getTimestamp();
        $Series = $this->hourlySeries($ArchiveID, $ID, $Info, $Start, $End);
        if ($Series === null || $Series === []) {
            return null;
        }

        $From = $this->ReadPropertyInteger('NightStart');
        $To = $this->ReadPropertyInteger('NightEnd');
        $Nights = [];
        foreach ($Series as $Hour => $KWh) {
            $H = (int) date('G', $Hour);
            $Inside = $From < $To ? ($H >= $From && $H < $To) : ($H >= $From || $H < $To);
            if (!$Inside) {
                continue;
            }
            // Ein Fenster über Mitternacht gehört zum Tag, an dem die Nacht beginnt
            $Key = date('Y-m-d', ($From >= $To && $H < $To) ? $Hour - 86400 : $Hour);
            $Nights[$Key][] = $KWh;
        }

        $Needed = (($To - $From) + 24) % 24 ?: 24;
        $Watts = [];
        foreach ($Nights as $Key => $Values) {
            // kWh in einer Stunde entspricht kW, mal 1000 ergibt Watt. Nächte mit weniger als der Hälfte der Stunden zählen nicht.
            if (count($Values) * 2 >= $Needed) {
                $Watts[$Key] = array_sum($Values) / count($Values) * 1000;
            }
        }
        if ($Watts === []) {
            return null;
        }

        ksort($Watts);
        $Sorted = array_values($Watts);
        sort($Sorted);
        $Mid = intdiv(count($Sorted), 2);
        $Median = count($Sorted) % 2 === 1 ? $Sorted[$Mid] : ($Sorted[$Mid - 1] + $Sorted[$Mid]) / 2;

        return [
            'Median'   => $Median,
            'Last'     => end($Watts),
            'Nights'   => $Watts,
            'Series'   => $Series,
            'DailyKWh' => array_sum($Series) / $Days
        ];
    }

    /**
     * Verbrauch in den Stunden, in denen niemand zu Hause war: Median der Leistung dieser Stunden und die Differenz zur Grundlast.
     * Die Differenz ist, was bei Abwesenheit mehr läuft als nachts im Normalbetrieb. Eine Stunde zählt als abwesend, wenn die
     * Abwesenheitsvariable mindestens eine halbe Stunde lang abwesend meldete. Null ohne Variable, ohne Archiv oder bei weniger als 3 Stunden.
     */
    private function absence(int $ArchiveID, array $Series, float $Base): ?array
    {
        $VarID = $this->ReadPropertyInteger('AbsenceVariableID');
        if ($VarID <= 0 || !IPS_VariableExists($VarID)) {
            return null;
        }
        if (!AC_GetLoggingStatus($ArchiveID, $VarID)) {
            $this->SendDebug(__FUNCTION__, 'Die Abwesenheitsvariable ist nicht archiviert', 0);
            return null;
        }

        $From = (int) min(array_keys($Series));
        $To = (int) max(array_keys($Series)) + 3600;
        // Einträge vor dem Zeitraum liefern den Zustand zu Beginn
        $Records = AC_GetLoggedValues($ArchiveID, $VarID, $From - 90 * 86400, $To, 0);
        usort($Records, fn (array $A, array $B): int => $A['TimeStamp'] <=> $B['TimeStamp']);
        $Inverted = $this->ReadPropertyBoolean('AbsenceInverted');
        $Events = [];
        foreach ($Records as $Record) {
            $Events[] = [(int) $Record['TimeStamp'], $Inverted ? !$Record['Value'] : (bool) $Record['Value']];
        }

        $Watts = [];
        $State = false;
        $Index = 0;
        foreach ($Series as $Hour => $KWh) {
            while (isset($Events[$Index]) && $Events[$Index][0] <= $Hour) {
                $State = $Events[$Index][1];
                $Index++;
            }
            $Absent = 0;
            $T = $Hour;
            $Cursor = $Index;
            $Current = $State;
            while (isset($Events[$Cursor]) && $Events[$Cursor][0] < $Hour + 3600) {
                $Absent += $Current ? $Events[$Cursor][0] - $T : 0;
                $T = $Events[$Cursor][0];
                $Current = $Events[$Cursor][1];
                $Cursor++;
            }
            $Absent += $Current ? $Hour + 3600 - $T : 0;
            if ($Absent >= 1800) {
                $Watts[] = $KWh * 1000;
            }
        }
        if (count($Watts) < 3) {
            return null;
        }

        sort($Watts);
        $Mid = intdiv(count($Watts), 2);
        $Load = count($Watts) % 2 === 1 ? $Watts[$Mid] : ($Watts[$Mid - 1] + $Watts[$Mid]) / 2;
        return ['Load' => $Load, 'Extra' => $Load - $Base, 'Hours' => count($Watts)];
    }

    /**
     * Quelle der Auswertung: die eigene Auswahl, sonst der Hauptzähler des Splitters.
     */
    private function sourceID(array $Sources): int
    {
        $Own = $this->ReadPropertyBoolean('OverrideSources') ? $this->ReadPropertyInteger('OverrideSourceID') : 0;
        return $Own > 0 ? $Own : (int) ($Sources['MainMeter'] ?? 0);
    }

    private function validate(array $Sources): string
    {
        $SourceID = $this->sourceID($Sources);
        if ($SourceID === 0) {
            return 'Keine Quelle: Im Splitter fehlt ein Hauptzähler und hier ist keine Quelle gewählt';
        }
        if (!IPS_VariableExists($SourceID)) {
            return 'Die Quelle existiert nicht';
        }
        foreach (['NightStart', 'NightEnd'] as $Name) {
            $Hour = $this->ReadPropertyInteger($Name);
            if ($Hour < 0 || $Hour > 23) {
                return $Name . ' liegt nicht zwischen 0 und 23';
            }
        }
        if ($this->ReadPropertyInteger('NightStart') === $this->ReadPropertyInteger('NightEnd')) {
            return 'Beginn und Ende des Nachtfensters sind gleich';
        }
        if ($this->ReadPropertyInteger('Days') < 1) {
            return 'Die Anzahl der Tage muss mindestens 1 sein';
        }
        return '';
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->MaintainVariable('BaseLoad', $this->Translate('Base load'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' W', 'DIGITS' => 1], 1, true);
        $this->MaintainVariable('LastNight', $this->Translate('Last night'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' W', 'DIGITS' => 1], 2, true);
        $this->MaintainVariable('YearKWh', $this->Translate('Base load per year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0], 3, true);
        $this->MaintainVariable('YearCost', $this->Translate('Base load cost per year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], 4, true);
        $this->MaintainVariable('Share', $this->Translate('Share of consumption'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' %', 'DIGITS' => 1], 5, true);
        $this->MaintainVariable('Standby', $this->Translate('Standby ranking'), VARIABLETYPE_STRING, $Value, 6, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 7, true);
        $this->hideVariable('Data');

        // Die Variablen zur Abwesenheit gibt es nur mit Abwesenheitsvariable
        $Away = $this->ReadPropertyInteger('AbsenceVariableID') > 0;
        $this->MaintainVariable('AbsenceLoad', $this->Translate('Load when away'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' W', 'DIGITS' => 1], 8, $Away);
        $this->MaintainVariable('AbsenceExtra', $this->Translate('Extra load when away'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' W', 'DIGITS' => 1], 9, $Away);
        $this->MaintainVariable('AbsenceHours', $this->Translate('Hours when away'), VARIABLETYPE_INTEGER, $Value + ['SUFFIX' => ' h'], 10, $Away);
    }

    /**
     * Daten und übersetzte Texte für die Kachel. Die module.html enthält keine Texte einer Sprache.
     */
    private function tileMessage(string $Json): string
    {
        $Data = json_decode($Json, true);
        $Data = is_array($Data) ? $Data : [];
        $Data['texts'] = [
            'baseLoad'  => $this->Translate('Base load'),
            'lastNight' => $this->Translate('Last night'),
            'typical'   => $this->Translate('Typical'),
            'yearKWh'   => $this->Translate('kWh per year'),
            'yearCost'  => $this->Translate('Cost per year'),
            'share'     => $this->Translate('Share of consumption'),
            'nights'    => $this->Translate('Last nights'),
            'ranking'   => $this->Translate('Standby ranking'),
            'noNight'   => $this->Translate('No night data'),
            'openHint'  => $this->Translate('Click or tap again: open the history'),
            'away'      => $this->Translate('When away'),
            'awayHours' => $this->Translate('hours evaluated'),
            'empty'     => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
