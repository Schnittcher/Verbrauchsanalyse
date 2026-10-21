<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

/**
 * Leckage-Wächter für Wasser (und Gas): Fließt rund um die Uhr etwas, obwohl niemand etwas braucht, ist das
 * ein Leck oder ein tropfender Spülkasten. Die Auswertung nutzt die Stundenwerte des Zählers.
 */
class Leckagewaechter extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    // So viele ganze Stunden vor der laufenden werden ausgewertet
    private const WINDOW_HOURS = 48;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('OverrideSourceID', 0);
        $this->RegisterPropertyFloat('Threshold', 1.0);
        $this->RegisterPropertyInteger('AlarmHours', 6);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('UpdateInterval', 15);

        $this->RegisterTimer('Calculate', 0, 'LKW_Calculate($_IPS[\'TARGET\']);');
        $this->registerSourceChild(2);

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
     * Prüft die letzten Stunden auf Dauerfluss und schreibt die Variablen.
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
        $ID = $this->sourceID($Sources);
        $Info = $this->unitInfoFor($ID, $Sources['Unit']);
        if ($ArchiveID === 0 || $Info['Family'] === 'energy' || !AC_GetLoggingStatus($ArchiveID, $ID)) {
            $this->SendDebug(__FUNCTION__, 'Quelle nicht nutzbar (kein Archiv oder Energieeinheit)', 0);
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Nur ganze Stunden, die laufende Stunde ist noch nicht vollständig
        $End = (int) strtotime(date('Y-m-d H:00:00'));
        $Series = $this->hourlySeries($ArchiveID, $ID, $Info, $End - self::WINDOW_HOURS * 3600, $End);
        if ($Series === null || $Series === []) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Liter je Stunde (die Basiseinheit ist m³)
        $Liters = array_map(fn (float $Value): float => $Value * 1000, $Series);
        $Threshold = $this->ReadPropertyFloat('Threshold');

        // Dauerfluss: wie viele Stunden hintereinander, von der letzten ganzen Stunde rückwärts, floss mindestens die Schwelle
        $Run = 0;
        $RunMin = null;
        foreach (array_reverse($Liters, true) as $Value) {
            if ($Value < $Threshold) {
                break;
            }
            $Run++;
            $RunMin = $RunMin === null ? $Value : min($RunMin, $Value);
        }

        $Last24 = array_slice($Liters, -24, null, true);
        $MinFlow = $Last24 === [] ? 0.0 : min($Last24);

        // Das Leck wird an der kleinsten Stundenmenge des Dauerflusses gemessen, mehr fließt mindestens immer
        $LossYear = $RunMin !== null ? $RunMin / 1000 * 8760 : 0.0;
        $Leak = $Run >= max(1, $this->ReadPropertyInteger('AlarmHours'));

        $this->SetValue('Leak', $Leak);
        $this->SetValue('ContinuousHours', $Run);
        $this->SetValue('MinFlow', round($MinFlow, 1));
        $this->SetValue('LossYear', round($LossYear, 1));
        $this->SetValue('LossCostYear', round($LossYear * (float) $Sources['PriceNow'], 2));
        $Message = $Run > 0
            ? sprintf($this->Translate('Continuous flow for %d hours, at least %s l/h'), $Run, number_format((float) $RunMin, 1, '.', ''))
            : $this->Translate('No continuous flow');
        $this->SetValue('Message', $Message);

        // Daten der Kachel: Liter je Stunde der letzten 48 ganzen Stunden und die Zahlen dazu
        $Hours = [];
        foreach ($Liters as $Hour => $Value) {
            $Hours[] = ['t' => $Hour, 'l' => round($Value, 2)];
        }
        $Json = (string) json_encode([
            'leak'       => $Leak,
            'run'        => $Run,
            'minFlow'    => round($MinFlow, 1),
            'lossYear'   => round($LossYear, 1),
            'lossCost'   => round($LossYear * (float) $Sources['PriceNow'], 2),
            'threshold'  => $Threshold,
            'alarmHours' => $this->ReadPropertyInteger('AlarmHours'),
            'sourceId'   => $ID,
            'clickOpen'  => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'message'    => $Message,
            'hours'      => $Hours
        ]);
        $this->SetValue('Data', $Json);
        $this->UpdateVisualizationValue($this->tileMessage($Json));

        $this->SetStatus(IS_ACTIVE);
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
        if ($this->ReadPropertyFloat('Threshold') < 0) {
            return 'Die Schwelle darf nicht negativ sein';
        }
        if ($this->ReadPropertyInteger('AlarmHours') < 1) {
            return 'Die Alarmzeit muss mindestens 1 Stunde sein';
        }
        return '';
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Leak = $Value + [
            'OPTIONS' => json_encode([
                ['Caption' => $this->Translate('OK'), 'ColorActive' => true, 'ColorValue' => 65280, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => false],
                ['Caption' => $this->Translate('Leak suspected'), 'ColorActive' => true, 'ColorValue' => 16711680, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => true]
            ])
        ];
        $this->MaintainVariable('Leak', $this->Translate('Leak suspected'), VARIABLETYPE_BOOLEAN, $Leak, 1, true);
        $this->MaintainVariable('ContinuousHours', $this->Translate('Continuous flow'), VARIABLETYPE_INTEGER, $Value + ['SUFFIX' => ' h'], 2, true);
        $this->MaintainVariable('MinFlow', $this->Translate('Minimum flow (24 hours)'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' l/h', 'DIGITS' => 1], 3, true);
        $this->MaintainVariable('LossYear', $this->Translate('Estimated loss per year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' m³', 'DIGITS' => 1], 4, true);
        $this->MaintainVariable('LossCostYear', $this->Translate('Estimated loss cost per year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], 5, true);
        $this->MaintainVariable('Message', $this->Translate('Result'), VARIABLETYPE_STRING, $Value, 6, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 7, true);
        $this->hideVariable('Data');
    }

    /**
     * Daten und übersetzte Texte für die Kachel. Die module.html enthält keine Texte einer Sprache.
     */
    private function tileMessage(string $Json): string
    {
        $Data = json_decode($Json, true);
        $Data = is_array($Data) ? $Data : [];
        $Data['texts'] = [
            'ok'        => $this->Translate('No leak suspected'),
            'leak'      => $this->Translate('Leak suspected'),
            'chart'     => $this->Translate('Flow per hour, last 48 hours'),
            'threshold' => $this->Translate('Threshold'),
            'openHint'  => $this->Translate('Click or tap again: open the history'),
            'hintRun'   => $this->Translate('Part of the continuous flow'),
            'hintAbove' => $this->Translate('Above the threshold'),
            'hintBelow' => $this->Translate('Below the threshold'),
            'run'       => $this->Translate('Continuous flow'),
            'minFlow'   => $this->Translate('Minimum flow (24 hours)'),
            'lossYear'  => $this->Translate('Estimated loss per year'),
            'lossCost'  => $this->Translate('Estimated loss cost per year'),
            'empty'     => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
