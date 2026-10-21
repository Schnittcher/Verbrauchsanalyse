<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';
require_once __DIR__ . '/../libs/CustomPeriodStrict.php';

/**
 * Lastspitzen: Die höchste Leistung je Stunde im gewählten Zeitraum, Spitzen je Tag, die größten Spitzen mit Uhrzeit und ein
 * optionaler Grenzwert mit Alarmvariable. Quelle ist der Gesamtverbrauch, eine Gruppe oder ein Gerät, gewählt in der Kachel.
 * Zähler liefern die mittlere Leistung einer Stunde, Leistungsvariablen den höchsten Wert der Stunde.
 */
class Lastspitzen extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;
    use CustomPeriod;

    // Zeiträume der Kachel in Tagen bis heute, 0 = eigener Zeitraum
    private const DAYS = [7, 30, 90, 365];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Days', 30);
        $this->RegisterPropertyFloat('Threshold', 0.0);
        $this->RegisterPropertyInteger('TopN', 5);
        $this->RegisterPropertyInteger('UpdateInterval', 15);

        $this->RegisterAttributeString('LastRequest', '');
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

        if ($this->mediumEmpty($Sources)) {
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
            $Request = $this->parseRequest((string) $Value);
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
     * Berechnet die Spitzen für den zuletzt gewählten Zeitraum neu, setzt die Variablen und schickt die Kachel.
     */
    public function Update(): void
    {
        $this->refresh();
    }

    // ---------------------------------------------------------------- Berechnung

    /**
     * Anfrage der Kachel: {"scope": "...", "days": 7|30|90|365} oder {"scope": "...", "from": "...", "to": "..."}.
     */
    private function parseRequest(string $Json): ?array
    {
        $Data = json_decode($Json, true);
        if (!is_array($Data)) {
            return null;
        }
        $Scope = is_string($Data['scope'] ?? null) ? $Data['scope'] : 'total';
        if (isset($Data['from'])) {
            $Custom = $this->parseCustom($Json);
            return $Custom === null ? null : ['scope' => $Scope, 'days' => 0, 'custom' => $Custom];
        }
        $Days = (int) ($Data['days'] ?? 0);
        return in_array($Days, self::DAYS, true) ? ['scope' => $Scope, 'days' => $Days, 'custom' => null] : null;
    }

    private function currentRequest(): array
    {
        $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
        if (is_array($Last) && isset($Last['days'])) {
            $Days = (int) $Last['days'];
            $Scope = is_string($Last['scope'] ?? null) ? $Last['scope'] : 'total';
            if ($Days === 0 && is_array($Last['custom'] ?? null)) {
                return ['scope' => $Scope, 'days' => 0, 'custom' => $Last['custom']];
            }
            if (in_array($Days, self::DAYS, true)) {
                return ['scope' => $Scope, 'days' => $Days, 'custom' => null];
            }
        }
        return ['scope' => 'total', 'days' => $this->ReadPropertyInteger('Days'), 'custom' => null];
    }

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Request = $this->currentRequest();
        $Data = $this->build($Request['scope'], $Request['days'], $Request['custom']);
        if ($Data !== null) {
            $this->SetValue('Data', (string) json_encode($Data));
        }
        $this->updateVariables();
        $this->UpdateVisualizationValue($this->tileMessage(''));
    }

    /**
     * Höchste Leistung je Stunde in W: bei Zählern die mittlere Leistung der Stunde, bei Leistungsvariablen der höchste Wert der Stunde.
     * Mehrere Quellen werden je Stunde addiert. Gibt die Stunden und die Zahl der genutzten Quellen zurück.
     *
     * @return array{hours: array<int, float>, used: int}
     */
    private function peakSeries(array $Config, int $ArchiveID, string $Scope, int $Start, int $End): array
    {
        $Hours = [];
        $Used = 0;
        foreach ($this->memberIDs($Config, $Scope) as $ID) {
            $Info = $this->unitInfoFor($ID, $Config['Unit']);
            if (!IPS_VariableExists($ID) || $Info['Family'] !== $Config['Unit']['Family'] || !AC_GetLoggingStatus($ArchiveID, $ID)) {
                continue;
            }
            $Series = [];
            if ($Info['Kind'] === 'power') {
                foreach (AC_GetAggregatedValues($ArchiveID, $ID, 0, $Start, $End, 0) as $Record) {
                    if ($Record['TimeStamp'] >= $Start && $Record['TimeStamp'] < $End) {
                        $Series[(int) $Record['TimeStamp']] = (float) $Record['Max'] * $Info['Factor'] * 1000;
                    }
                }
            } else {
                $Hourly = $this->hourlySeries($ArchiveID, $ID, $Info, $Start, $End);
                if ($Hourly === null) {
                    continue;
                }
                foreach ($Hourly as $Time => $Value) {
                    $Series[$Time] = $Value * 1000;
                }
            }
            $Used++;
            foreach ($Series as $Time => $Value) {
                $Hours[$Time] = ($Hours[$Time] ?? 0.0) + $Value;
            }
        }
        ksort($Hours);
        return ['hours' => $Hours, 'used' => $Used];
    }

    /**
     * Größte Stunde im Bereich: ['time' => Stunde, 'value' => W] oder null.
     */
    private function maxIn(array $Hours, int $From, int $To): ?array
    {
        $Best = null;
        foreach ($Hours as $Time => $Value) {
            if ($Time >= $From && $Time < $To && ($Best === null || $Value > $Best['value'])) {
                $Best = ['time' => (int) $Time, 'value' => round($Value, 1)];
            }
        }
        return $Best;
    }

    private function build(string $Scope, int $Days, ?array $Custom): ?array
    {
        $Config = $this->readConfiguration();
        if ($Config['NoSources'] || ($Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return null;
        }
        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return null;
        }
        $Scopes = $this->scopes($Config, $this->Translate('Total consumption'));
        if (!in_array($Scope, array_column($Scopes, 'id'), true)) {
            $Scope = 'total';
        }

        $Today = new DateTimeImmutable('today');
        $Now = time();
        if ($Days === 0 && $Custom !== null) {
            $Start = $Custom['start'];
            $End = min($Custom['end'], $Now + 1);
            $Label = date('d.m.Y', $Start) . ' - ' . date('d.m.Y', $Custom['end'] - 1);
        } else {
            $Start = $Today->modify('-' . ($Days - 1) . ' days')->getTimestamp();
            $End = $Now + 1;
            $Label = sprintf($this->Translate('Last %d days'), $Days);
        }

        $Series = $this->peakSeries($Config, $ArchiveID, $Scope, $Start, $End);
        $Hours = $Series['hours'];

        // Spitze je Tag
        $DayPeaks = [];
        foreach ($Hours as $Time => $Value) {
            $Key = date('Y-m-d', $Time);
            if (!isset($DayPeaks[$Key]) || $Value > $DayPeaks[$Key]['value']) {
                $DayPeaks[$Key] = ['day' => $Key, 'time' => (int) $Time, 'value' => round($Value, 1)];
            }
        }
        ksort($DayPeaks);

        // Die größten Stunden, je Tag höchstens eine, damit eine lange Spitze nicht die ganze Liste füllt
        $Top = array_values($DayPeaks);
        usort($Top, fn (array $A, array $B): int => $B['value'] <=> $A['value']);
        $Top = array_slice($Top, 0, max(1, $this->ReadPropertyInteger('TopN')));

        $Threshold = $this->ReadPropertyFloat('Threshold');
        $Over = 0;
        if ($Threshold > 0) {
            foreach ($Hours as $Value) {
                if ($Value > $Threshold) {
                    $Over++;
                }
            }
        }
        $Peak = $Top[0] ?? null;
        $Name = $Scopes[array_search($Scope, array_column($Scopes, 'id'), true)]['name'];

        return [
            'scope'      => $Scope,
            'name'       => $Name,
            'label'      => $Label,
            'days'       => $Days,
            'sources'    => $Series['used'],
            'peak'       => $Peak === null ? null : ['time' => $Peak['time'], 'value' => $Peak['value']],
            'average'    => $DayPeaks === [] ? null : round(array_sum(array_column($DayPeaks, 'value')) / count($DayPeaks), 1),
            'threshold'  => $Threshold,
            'overHours'  => $Over,
            'dayPeaks'   => array_values($DayPeaks),
            'top'        => $Top,
            'scopes'     => $Scopes,
            'updated'    => $Now
        ];
    }

    /**
     * Variablen: Spitze heute, im laufenden Monat und im laufenden Jahr, jeweils für den Gesamtverbrauch, dazu der Alarm bei Grenzwert.
     */
    private function updateVariables(): void
    {
        $Config = $this->readConfiguration();
        $ArchiveID = $this->getArchiveID();
        if ($Config['NoSources'] || $ArchiveID === 0) {
            return;
        }
        $Today = new DateTimeImmutable('today');
        $YearStart = $Today->modify('first day of january this year')->getTimestamp();
        $Series = $this->peakSeries($Config, $ArchiveID, 'total', $YearStart, time() + 1);
        $Hours = $Series['hours'];
        $Ranges = [
            'Today' => $Today->getTimestamp(),
            'Month' => $Today->modify('first day of this month')->getTimestamp(),
            'Year'  => $YearStart
        ];
        foreach ($Ranges as $Name => $From) {
            $Peak = $this->maxIn($Hours, $From, PHP_INT_MAX);
            $this->SetValue('Peak' . $Name, $Peak === null ? 0.0 : $Peak['value']);
            $this->SetValue('Peak' . $Name . 'Time', $Peak === null ? 0 : $Peak['time']);
        }
        $Threshold = $this->ReadPropertyFloat('Threshold');
        if ($Threshold > 0) {
            $this->SetValue('Alarm', $this->GetValue('PeakToday') > $Threshold);
        }
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(string $Message): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        $Request = $this->currentRequest();
        return (string) json_encode([
            'data'       => is_array($Data) ? $Data : null,
            'scope'      => $Request['scope'],
            'days'       => $Request['days'],
            'dayOptions' => self::DAYS,
            'today'      => date('Y-m-d'),
            'message'    => $Message,
            'texts'      => [
                'days'      => $this->Translate('days'),
                'custom'    => $this->Translate('Custom'),
                'from'      => $this->Translate('From'),
                'to'        => $this->Translate('To'),
                'show'      => $this->Translate('Show'),
                'source'    => $this->Translate('Source'),
                'peak'      => $this->Translate('Highest peak'),
                'average'   => $this->Translate('Average daily peak'),
                'over'      => $this->Translate('Hours over the limit'),
                'limit'     => $this->Translate('Limit'),
                'peaks'     => $this->Translate('Daily peaks'),
                'top'       => $this->Translate('Largest peaks'),
                'hourNote'  => $this->Translate('Peak = highest hourly power'),
                'empty'     => $this->Translate('No data yet')
            ]
        ]);
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Power = $Value + ['SUFFIX' => ' W', 'DIGITS' => 0];
        $Time = ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME];
        $this->MaintainVariable('PeakToday', $this->Translate('Peak today'), VARIABLETYPE_FLOAT, $Power, 1, true);
        $this->MaintainVariable('PeakTodayTime', $this->Translate('Time of the peak today'), VARIABLETYPE_INTEGER, $Time, 2, true);
        $this->MaintainVariable('PeakMonth', $this->Translate('Peak this month'), VARIABLETYPE_FLOAT, $Power, 3, true);
        $this->MaintainVariable('PeakMonthTime', $this->Translate('Time of the peak this month'), VARIABLETYPE_INTEGER, $Time, 4, true);
        $this->MaintainVariable('PeakYear', $this->Translate('Peak this year'), VARIABLETYPE_FLOAT, $Power, 5, true);
        $this->MaintainVariable('PeakYearTime', $this->Translate('Time of the peak this year'), VARIABLETYPE_INTEGER, $Time, 6, true);
        $this->MaintainVariable('Alarm', $this->Translate('Limit exceeded today'), VARIABLETYPE_BOOLEAN, $Value, 7, $this->ReadPropertyFloat('Threshold') > 0);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 8, true);
        $this->hideVariable('Data');
    }

    private function validate(): string
    {
        if (!in_array($this->ReadPropertyInteger('Days'), self::DAYS, true)) {
            return 'Der Zeitraum ist ungültig';
        }
        if ($this->ReadPropertyFloat('Threshold') < 0) {
            return 'Der Grenzwert darf nicht negativ sein';
        }
        if ($this->ReadPropertyInteger('TopN') < 1 || $this->ReadPropertyInteger('TopN') > 50) {
            return 'Die Anzahl der Spitzen muss zwischen 1 und 50 liegen';
        }
        $Sources = $this->requestSources();
        if (is_array($Sources) && ($Sources['Unit']['Family'] ?? 'energy') !== 'energy') {
            return 'Das Medium muss Energie liefern (kWh, Wh, kW oder W)';
        }
        if ($this->ReadPropertyInteger('UpdateInterval') < 1) {
            return 'Das Aktualisierungsintervall ist ungültig';
        }
        return '';
    }
}
