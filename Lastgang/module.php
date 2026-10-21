<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';

/**
 * Lastgang: Das typische Tagesprofil als Heatmap aus Wochentag und Stunde, mit mittlerer Leistung je Feld, dem heutigen Tag
 * im Vergleich und Kennzahlen wie Grundlast, Spitzenzeit und Tagesverbrauch. Quelle ist der Gesamtverbrauch, eine Gruppe
 * oder ein einzelnes Gerät, gewählt in der Kachel.
 */
class Lastgang extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;

    private const WEEKS = [4, 12, 26, 52];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Weeks', 12);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

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

        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1, true);
        $this->hideVariable('Data');

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
            $Request = json_decode((string) $Value, true);
            $Weeks = is_array($Request) ? (int) ($Request['weeks'] ?? 0) : 0;
            $Scope = is_array($Request) && is_string($Request['scope'] ?? null) ? $Request['scope'] : 'total';
            if (!in_array($Weeks, self::WEEKS, true)) {
                $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The period is invalid')));
                return;
            }
            $this->WriteAttributeString('LastRequest', (string) json_encode(['scope' => $Scope, 'weeks' => $Weeks]));
            $this->refresh();
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Berechnet das Profil für die zuletzt gewählte Quelle und Dauer neu und schickt es an die Kachel.
     */
    public function Update(): void
    {
        $this->refresh();
    }

    // ---------------------------------------------------------------- Berechnung

    private function currentRequest(): array
    {
        $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
        $Weeks = is_array($Last) ? (int) ($Last['weeks'] ?? 0) : 0;
        return [
            'scope' => is_array($Last) && is_string($Last['scope'] ?? null) ? $Last['scope'] : 'total',
            'weeks' => in_array($Weeks, self::WEEKS, true) ? $Weeks : $this->ReadPropertyInteger('Weeks')
        ];
    }

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Request = $this->currentRequest();
        $Data = $this->build($Request['scope'], $Request['weeks']);
        if ($Data !== null) {
            $this->SetValue('Data', (string) json_encode($Data));
        }
        $this->UpdateVisualizationValue($this->tileMessage(''));
    }

    private function build(string $Scope, int $Weeks): ?array
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
        $Start = $Today->modify('-' . ($Weeks * 7) . ' days')->getTimestamp();
        $End = time();
        $Hours = [];
        $Used = 0;
        foreach ($this->memberIDs($Config, $Scope) as $ID) {
            $Info = $this->unitInfoFor($ID, $Config['Unit']);
            if (!IPS_VariableExists($ID) || $Info['Family'] !== $Config['Unit']['Family'] || !AC_GetLoggingStatus($ArchiveID, $ID)) {
                continue;
            }
            $Series = $this->hourlySeries($ArchiveID, $ID, $Info, $Start, $End);
            if ($Series === null) {
                continue;
            }
            $Used++;
            foreach ($Series as $Time => $Value) {
                $Hours[$Time] = ($Hours[$Time] ?? 0.0) + $Value;
            }
        }
        ksort($Hours);

        // Mittlere Leistung je Wochentag und Stunde in W (Verbrauch einer Stunde in kWh mal 1000)
        $Sum = array_fill(0, 7, array_fill(0, 24, 0.0));
        $Count = array_fill(0, 7, array_fill(0, 24, 0));
        $TodayHours = array_fill(0, 24, null);
        $TodayStart = $Today->getTimestamp();
        foreach ($Hours as $Time => $Value) {
            $Day = (int) date('N', $Time) - 1;
            $Hour = (int) date('G', $Time);
            if ($Time >= $TodayStart) {
                $TodayHours[$Hour] = round($Value * 1000, 1);
                // Die laufende Stunde ist unvollständig und verfälscht den Schnitt
                continue;
            }
            $Sum[$Day][$Hour] += $Value * 1000;
            $Count[$Day][$Hour]++;
        }
        $Cells = [];
        $Max = 0.0;
        $Peak = null;
        $AllSum = 0.0;
        $AllCount = 0;
        $HourSum = array_fill(0, 24, 0.0);
        $HourCount = array_fill(0, 24, 0);
        $DayKWh = [];
        for ($Day = 0; $Day < 7; $Day++) {
            $Row = [];
            $DaySum = 0.0;
            for ($Hour = 0; $Hour < 24; $Hour++) {
                if ($Count[$Day][$Hour] === 0) {
                    $Row[] = null;
                    continue;
                }
                $Average = $Sum[$Day][$Hour] / $Count[$Day][$Hour];
                $Row[] = round($Average, 1);
                $DaySum += $Average;
                $AllSum += $Sum[$Day][$Hour];
                $AllCount += $Count[$Day][$Hour];
                $HourSum[$Hour] += $Average;
                $HourCount[$Hour]++;
                if ($Average > $Max) {
                    $Max = $Average;
                    $Peak = ['day' => $Day, 'hour' => $Hour, 'value' => round($Average, 1)];
                }
            }
            $Cells[] = $Row;
            $DayKWh[] = round($DaySum / 1000, 2);
        }
        // Grundlast: die niedrigste mittlere Leistung über alle Wochentage zu einer Stunde
        $Base = null;
        for ($Hour = 0; $Hour < 24; $Hour++) {
            if ($HourCount[$Hour] > 0) {
                $Value = $HourSum[$Hour] / $HourCount[$Hour];
                $Base = $Base === null ? $Value : min($Base, $Value);
            }
        }

        $Name = $Scopes[array_search($Scope, array_column($Scopes, 'id'), true)]['name'];
        return [
            'scope'    => $Scope,
            'name'     => $Name,
            'weeks'    => $Weeks,
            'sources'  => $Used,
            'cells'    => $Cells,
            'today'    => $TodayHours,
            'todayDay' => (int) date('N') - 1,
            'average'  => $AllCount > 0 ? round($AllSum / $AllCount, 1) : null,
            'base'     => $Base === null ? null : round($Base, 1),
            'peak'     => $Peak,
            'max'      => round($Max, 1),
            'dayKWh'   => $DayKWh,
            'scopes'   => $Scopes,
            'updated'  => time()
        ];
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(string $Message): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        $Request = $this->currentRequest();
        return (string) json_encode([
            'data'        => is_array($Data) ? $Data : null,
            'scope'       => $Request['scope'],
            'weeks'       => $Request['weeks'],
            'weekOptions' => self::WEEKS,
            'message'     => $Message,
            'texts'       => [
                'weeks'    => $this->Translate('weeks'),
                'source'   => $this->Translate('Source'),
                'today'    => $this->Translate('Today'),
                'average'  => $this->Translate('Average power'),
                'base'     => $this->Translate('Base load'),
                'peak'     => $this->Translate('Peak time'),
                'dayTotal' => $this->Translate('Per day'),
                'typical'  => $this->Translate('Typical'),
                'profile'  => $this->Translate('Daily profile'),
                'hour'     => $this->Translate('Hour'),
                'noData'   => $this->Translate('No data'),
                'empty'    => $this->Translate('No data yet')
            ]
        ]);
    }

    private function validate(): string
    {
        if (!in_array($this->ReadPropertyInteger('Weeks'), self::WEEKS, true)) {
            return 'Die Anzahl der Wochen ist ungültig';
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
