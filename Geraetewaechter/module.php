<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';

/**
 * Gerätewächter: Vergleicht den Verbrauch jedes Geräts (und jedes Gruppenzählers) der letzten Tage mit seinem eigenen Normalverhalten
 * aus den Wochen davor und meldet Abweichungen nach oben und auf Wunsch nach unten. Der Vergleich nimmt je Tag den Median
 * desselben Wochentags, damit Gewohnheiten wie ein Waschtag keine Meldung auslösen.
 */
class Geraetewaechter extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;

    // Mindestzahl an Tagen im Vergleichszeitraum und ab welcher Streuung eine Abweichung als auffällig gilt
    private const MIN_BASELINE_DAYS = 7;
    private const MIN_SAME_WEEKDAY = 3;
    private const Z_LIMIT = 3.0;
    private const SPARK_DAYS = 14;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('BaselineDays', 28);
        $this->RegisterPropertyInteger('CheckDays', 3);
        $this->RegisterPropertyInteger('ThresholdPercent', 40);
        $this->RegisterPropertyFloat('MinChange', 0.05);
        $this->RegisterPropertyBoolean('AlertLow', true);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

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

        if ($this->mediumEmpty($Sources)) {
            $this->SendDebug(__FUNCTION__, 'Das gewählte Medium ist nicht eingerichtet', 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(IS_INACTIVE);
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
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Prüft alle Geräte neu, setzt Variablen und Kachel und gibt die Zahl der Auffälligkeiten zurück.
     */
    public function Check(): int
    {
        return $this->refresh();
    }

    // ---------------------------------------------------------------- Berechnung

    private function refresh(): int
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return 0;
        }
        $Config = $this->readConfiguration();
        if ($Config['NoSources'] || ($Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return 0;
        }
        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return 0;
        }

        $Devices = $this->evaluateDevices($Config, $ArchiveID);
        $Flagged = array_values(array_filter($Devices, fn (array $D): bool => $D['status'] === 'high' || $D['status'] === 'low'));
        usort($Devices, function (array $A, array $B): int
        {
            $Rank = fn (array $D): int => in_array($D['status'], ['high', 'low'], true) ? 0 : ($D['status'] === 'ok' ? 1 : 2);
            return [$Rank($A), -abs($A['z'] ?? 0)] <=> [$Rank($B), -abs($B['z'] ?? 0)];
        });

        $Lines = [];
        foreach ($Flagged as $Device) {
            $Lines[] = sprintf(
                $Device['status'] === 'high' ? $this->Translate('%s: %s kWh/day instead of %s') : $this->Translate('%s: only %s kWh/day instead of %s'),
                $Device['name'],
                number_format($Device['recent'], 2, '.', ''),
                number_format($Device['normal'], 2, '.', '')
            );
        }
        $this->SetValue('Count', count($Flagged));
        $this->SetValue('Alarm', count($Flagged) > 0);
        $this->SetValue('Message', implode("\n", $Lines));
        $this->SetValue('Data', (string) json_encode([
            'devices'  => $Devices,
            'unit'     => $Config['Unit']['Out'],
            'baseline' => $this->ReadPropertyInteger('BaselineDays'),
            'check'    => $this->ReadPropertyInteger('CheckDays'),
            'updated'  => time()
        ]));
        $this->UpdateVisualizationValue($this->tileMessage());
        return count($Flagged);
    }

    /**
     * Prüft jedes Gerät und jeden Gruppenzähler gegen das eigene Normalverhalten.
     *
     * @return array<int, array<string, mixed>>
     */
    private function evaluateDevices(array $Config, int $ArchiveID): array
    {
        $Sources = [];
        foreach ($Config['Consumers'] as $ID => $Consumer) {
            $Sources[(int) $ID] = ['name' => $Consumer['Name'], 'type' => 'var'];
        }
        foreach ($Config['Groups'] as $Group) {
            if ($Group['MeterID'] > 0) {
                $Sources[(int) $Group['MeterID']] = ['name' => $Group['Name'], 'type' => 'group'];
            }
        }

        $Baseline = $this->ReadPropertyInteger('BaselineDays');
        $Check = $this->ReadPropertyInteger('CheckDays');
        $Today = new DateTimeImmutable('today');
        $End = $Today->getTimestamp();
        $Start = $Today->modify('-' . max($Baseline + $Check, self::SPARK_DAYS) . ' days')->getTimestamp();

        $Devices = [];
        foreach ($Sources as $ID => $Source) {
            $Entry = ['id' => $ID, 'name' => $Source['name'], 'type' => $Source['type'], 'status' => 'nodata'];
            $Info = $this->unitInfoFor($ID, $Config['Unit']);
            if (IPS_VariableExists($ID) && $Info['Family'] === $Config['Unit']['Family'] && AC_GetLoggingStatus($ArchiveID, $ID)) {
                $Entry = array_merge($Entry, $this->judge($this->dailySeries($ArchiveID, $ID, $Info, $Start, $End), $Today, $Baseline, $Check));
            }
            $Devices[] = $Entry;
        }
        return $Devices;
    }

    /**
     * Beurteilt eine Tagesreihe. Tage nach dem ersten Datensatz ohne Eintrag zählen als 0 (ein Zähler, der sich nicht ändert, wird nicht
     * archiviert), Tage davor fehlen. Gibt Status, die letzten Tage je Tag und bei genug Daten Wert, Normalwert und Abweichung zurück.
     */
    private function judge(array $Series, DateTimeImmutable $Today, int $Baseline, int $Check): array
    {
        if ($Series === []) {
            return ['status' => 'nodata'];
        }
        $First = min(array_keys($Series));
        $Values = [];
        for ($Offset = $Baseline + $Check; $Offset >= 1; $Offset--) {
            $Day = $Today->modify('-' . $Offset . ' days');
            $Time = $Day->getTimestamp();
            $Values[$Time] = $Time >= $First ? ($Series[$Time] ?? 0.0) : null;
        }
        $Spark = [];
        for ($Offset = self::SPARK_DAYS; $Offset >= 1; $Offset--) {
            $Time = $Today->modify('-' . $Offset . ' days')->getTimestamp();
            $Spark[] = round($Time >= $First ? ($Series[$Time] ?? 0.0) : 0.0, 3);
        }

        $Times = array_keys($Values);
        $RecentTimes = array_slice($Times, -$Check);
        $BaseTimes = array_slice($Times, 0, count($Times) - $Check);
        $Base = [];
        foreach ($BaseTimes as $Time) {
            if ($Values[$Time] !== null) {
                $Base[$Time] = $Values[$Time];
            }
        }
        if (count($Base) < self::MIN_BASELINE_DAYS) {
            return ['status' => 'nodata', 'spark' => $Spark];
        }

        $Median = fn (array $List): float => $this->median($List);
        $Overall = $Median(array_values($Base));
        $Mad = $Median(array_map(fn (float $V): float => abs($V - $Overall), array_values($Base)));

        $RecentSum = 0.0;
        $ExpectedSum = 0.0;
        foreach ($RecentTimes as $Time) {
            $Weekday = date('N', $Time);
            $Same = [];
            foreach ($Base as $BaseTime => $Value) {
                if (date('N', $BaseTime) === $Weekday) {
                    $Same[] = $Value;
                }
            }
            $ExpectedSum += count($Same) >= self::MIN_SAME_WEEKDAY ? $Median($Same) : $Overall;
            $RecentSum += $Values[$Time] ?? 0.0;
        }
        $Recent = $RecentSum / $Check;
        $Normal = $ExpectedSum / $Check;
        $Difference = $Recent - $Normal;
        $Sigma = max(1.4826 * $Mad, 0.1 * $Normal, 1e-6);
        $Z = $Difference / $Sigma;
        $Change = $Normal > 0 ? $Difference / $Normal * 100 : null;
        $Threshold = $this->ReadPropertyInteger('ThresholdPercent');
        $MinChange = $this->ReadPropertyFloat('MinChange');

        $Status = 'ok';
        $Higher = $Difference >= $MinChange && $Z >= self::Z_LIMIT && ($Change === null || $Change >= $Threshold);
        $Lower = $this->ReadPropertyBoolean('AlertLow') && -$Difference >= $MinChange && $Z <= -self::Z_LIMIT && $Change !== null && $Change <= -$Threshold;
        if ($Higher) {
            $Status = 'high';
        } elseif ($Lower) {
            $Status = 'low';
        }

        return [
            'status' => $Status,
            'recent' => round($Recent, 3),
            'normal' => round($Normal, 3),
            'change' => $Change === null ? null : round($Change, 1),
            'z'      => round($Z, 1),
            'spark'  => $Spark
        ];
    }

    private function median(array $List): float
    {
        sort($List);
        $Count = count($List);
        if ($Count === 0) {
            return 0.0;
        }
        $Middle = intdiv($Count, 2);
        return $Count % 2 === 1 ? (float) $List[$Middle] : ((float) $List[$Middle - 1] + (float) $List[$Middle]) / 2;
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        return (string) json_encode([
            'data'      => is_array($Data) ? $Data : null,
            'clickOpen' => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'texts'     => [
                'ok'        => $this->Translate('All devices behave normally'),
                'alarm'     => $this->Translate('Unusual devices'),
                'normal'    => $this->Translate('normal'),
                'recent'    => $this->Translate('last days'),
                'perDay'    => $this->Translate('per day'),
                'high'      => $this->Translate('Using more than usual'),
                'low'       => $this->Translate('Using less than usual'),
                'nodata'    => $this->Translate('Not enough data'),
                'fine'      => $this->Translate('Inconspicuous'),
                'last'      => $this->Translate('Last 14 days'),
                'openHint'  => $this->Translate('Click or tap again: open the history'),
                'empty'     => $this->Translate('No data yet')
            ]
        ]);
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->MaintainVariable('Alarm', $this->Translate('Unusual device'), VARIABLETYPE_BOOLEAN, $Value, 1, true);
        $this->MaintainVariable('Count', $this->Translate('Number of unusual devices'), VARIABLETYPE_INTEGER, $Value, 2, true);
        $this->MaintainVariable('Message', $this->Translate('Message'), VARIABLETYPE_STRING, $Value, 3, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 4, true);
        $this->hideVariable('Data');
    }

    private function validate(): string
    {
        $Baseline = $this->ReadPropertyInteger('BaselineDays');
        $Check = $this->ReadPropertyInteger('CheckDays');
        if ($Baseline < 14 || $Baseline > 90) {
            return 'Der Vergleichszeitraum muss zwischen 14 und 90 Tagen liegen';
        }
        if ($Check < 1 || $Check > 7) {
            return 'Die geprüften Tage müssen zwischen 1 und 7 liegen';
        }
        if ($this->ReadPropertyInteger('ThresholdPercent') < 5 || $this->ReadPropertyInteger('ThresholdPercent') > 500) {
            return 'Die Schwelle muss zwischen 5 und 500 Prozent liegen';
        }
        if ($this->ReadPropertyFloat('MinChange') < 0) {
            return 'Die Mindeständerung darf nicht negativ sein';
        }
        if ($this->ReadPropertyInteger('UpdateInterval') < 1) {
            return 'Das Aktualisierungsintervall ist ungültig';
        }
        return '';
    }
}
