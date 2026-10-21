<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

/**
 * Sparziel: Der Verbrauch dieses Jahres (oder Monats) gegen denselben Zeitraum im Vorjahr, mit einem Ziel in Prozent.
 * Die Instanz zeigt, ob man auf Kurs ist, und rechnet die Einsparung hoch. Mit einer Außentemperatur lässt sich das
 * Vorjahr witterungsbereinigt vergleichen.
 */
class Sparziel extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('OverrideSourceID', 0);
        $this->RegisterPropertyString('Period', 'year');
        $this->RegisterPropertyFloat('TargetPercent', 10.0);
        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyFloat('RoomTemperature', 20.0);
        $this->RegisterPropertyFloat('HeatingLimit', 15.0);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);

        $this->RegisterTimer('Calculate', 0, 'SPZ_Calculate($_IPS[\'TARGET\']);');
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

        $Sources = $this->requestSources();
        $this->maintainVariables($Sources['Unit']['Out'] ?? 'kWh');

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
     * Vergleicht den Verbrauch mit dem Vorjahr, prüft das Ziel, rechnet auf das Periodenende hoch und schreibt die Variablen.
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
        $SourceID = $this->sourceID($Sources);
        $Info = $this->unitInfoFor($SourceID, $Sources['Unit']);
        if ($ArchiveID === 0 || !AC_GetLoggingStatus($ArchiveID, $SourceID)) {
            $this->SendDebug(__FUNCTION__, 'Quelle nicht archiviert', 0);
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Zeiträume bis gestern (der laufende Tag ist nicht vollständig), das Vorjahr über dieselbe Dauer und als ganzer Zeitraum
        $Today = new DateTimeImmutable('today');
        $End = $Today->getTimestamp();
        $Month = $this->ReadPropertyString('Period') === 'month';
        $Start = $Month ? $Today->modify('first day of this month') : $Today->setDate((int) $Today->format('Y'), 1, 1);
        $Year = new DateInterval('P1Y');
        $PrevStart = $Start->sub($Year)->getTimestamp();
        $PrevEnd = $Today->sub($Year)->getTimestamp();
        $PrevFullEnd = $Month ? $Start->sub($Year)->modify('first day of next month')->getTimestamp() : $Start->getTimestamp();

        $Start = $Start->getTimestamp();
        $Actual = $End > $Start ? $this->consumptionBetween($ArchiveID, $SourceID, $Info, $Start, $End, 1) : 0.0;
        $Reference = $this->consumptionBetween($ArchiveID, $SourceID, $Info, $PrevStart, max($PrevStart, $PrevEnd), 1);
        $ReferenceFull = $this->consumptionBetween($ArchiveID, $SourceID, $Info, $PrevStart, $PrevFullEnd, 1);

        // Witterungsbereinigt: Das Vorjahr auf das Wetter dieses Jahres umrechnen (Verhältnis der Heizgradtage)
        $Adjusted = false;
        $TemperatureID = $this->ReadPropertyInteger('TemperatureID');
        if ($TemperatureID > 0 && AC_GetLoggingStatus($ArchiveID, $TemperatureID)) {
            $Room = $this->ReadPropertyFloat('RoomTemperature');
            $Limit = $this->ReadPropertyFloat('HeatingLimit');
            $DegreeNow = $this->degreeDaysBetween($ArchiveID, $TemperatureID, $Start, $End, $Room, $Limit);
            $DegreePrev = $this->degreeDaysBetween($ArchiveID, $TemperatureID, $PrevStart, $PrevEnd, $Room, $Limit);
            if ($DegreeNow > 0 && $DegreePrev > 0) {
                $Reference *= $DegreeNow / $DegreePrev;
                $ReferenceFull *= $DegreeNow / $DegreePrev;
                $Adjusted = true;
            }
        }

        $Target = $this->ReadPropertyFloat('TargetPercent');
        $Change = $Reference > 0 ? ($Actual - $Reference) / $Reference * 100 : 0.0;
        $Progress = $Target > 0 ? -$Change / $Target * 100 : 0.0;
        $OnTrack = $Reference > 0 && $Change <= -$Target;

        // Hochrechnung: Die Veränderung gilt für den ganzen Zeitraum, verglichen mit dem Vorjahr
        $Projected = $ReferenceFull * (1 + $Change / 100);
        $TargetValue = $ReferenceFull * (1 - $Target / 100);
        $Savings = $ReferenceFull - $Projected;
        $Price = (float) $Sources['PriceNow'];

        $this->SetValue('Actual', round($Actual, 2));
        $this->SetValue('Reference', round($Reference, 2));
        $this->SetValue('Change', round($Change, 1));
        $this->SetValue('Progress', round($Progress, 0));
        $this->SetValue('OnTrack', $OnTrack);
        $this->SetValue('Projected', round($Projected, 1));
        $this->SetValue('TargetValue', round($TargetValue, 1));
        $this->SetValue('Savings', round($Savings, 1));
        $this->SetValue('SavingsCost', round($Savings * $Price, 2));

        $Message = $Reference > 0
            ? sprintf(
                $this->Translate('%s %% to last year, target -%s %% (%s %% reached), projection %s %s'),
                ($Change > 0 ? '+' : '') . number_format($Change, 1, '.', ''),
                number_format($Target, 0, '.', ''),
                number_format(max(0.0, $Progress), 0, '.', ''),
                number_format($Projected, 0, '.', ''),
                $Info['Out']
            )
            : $this->Translate('No reference data for the same period last year yet');
        $this->SetValue('Message', $Message);

        $Json = (string) json_encode([
            'unit'         => $Info['Out'],
            'period'       => $Month ? 'month' : 'year',
            'adjusted'     => $Adjusted,
            'actual'       => round($Actual, 2),
            'reference'    => round($Reference, 2),
            'change'       => round($Change, 1),
            'target'       => $Target,
            'progress'     => round($Progress, 0),
            'onTrack'      => $OnTrack,
            'hasReference' => $Reference > 0,
            'projected'    => round($Projected, 1),
            'targetValue'  => round($TargetValue, 1),
            'savings'      => round($Savings, 1),
            'savingsCost'  => round($Savings * $Price, 2),
            'sourceId'     => $SourceID,
            'clickOpen'    => $this->ReadPropertyBoolean('OpenChartOnClick')
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
        if ($this->ReadPropertyFloat('TargetPercent') <= 0 || $this->ReadPropertyFloat('TargetPercent') >= 100) {
            return 'Das Ziel muss zwischen 0 und 100 Prozent liegen';
        }
        $TemperatureID = $this->ReadPropertyInteger('TemperatureID');
        if ($TemperatureID > 0 && !IPS_VariableExists($TemperatureID)) {
            return 'Die Temperaturvariable existiert nicht';
        }
        if ($this->ReadPropertyFloat('RoomTemperature') <= $this->ReadPropertyFloat('HeatingLimit')) {
            return 'Die Raumtemperatur muss über der Heizgrenze liegen';
        }
        return '';
    }

    private function maintainVariables(string $Unit): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Amount = $Value + ['SUFFIX' => ' ' . $Unit, 'DIGITS' => 1];
        $OnTrack = $Value + [
            'OPTIONS' => json_encode([
                ['Caption' => $this->Translate('Behind the goal'), 'ColorActive' => true, 'ColorValue' => 16744192, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => false],
                ['Caption' => $this->Translate('On track'), 'ColorActive' => true, 'ColorValue' => 65280, 'ContentColorActive' => false, 'ContentColorValue' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => true]
            ])
        ];
        $this->MaintainVariable('Actual', $this->Translate('Consumption so far'), VARIABLETYPE_FLOAT, $Amount, 1, true);
        $this->MaintainVariable('Reference', $this->Translate('Same period last year'), VARIABLETYPE_FLOAT, $Amount, 2, true);
        $this->MaintainVariable('Change', $this->Translate('Change to last year'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' %', 'DIGITS' => 1], 3, true);
        $this->MaintainVariable('Progress', $this->Translate('Goal reached'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' %', 'DIGITS' => 0], 4, true);
        $this->MaintainVariable('OnTrack', $this->Translate('On track'), VARIABLETYPE_BOOLEAN, $OnTrack, 5, true);
        $this->MaintainVariable('Projected', $this->Translate('Projection for the whole period'), VARIABLETYPE_FLOAT, $Amount, 6, true);
        $this->MaintainVariable('TargetValue', $this->Translate('Target for the whole period'), VARIABLETYPE_FLOAT, $Amount, 7, true);
        $this->MaintainVariable('Savings', $this->Translate('Projected saving'), VARIABLETYPE_FLOAT, $Amount, 8, true);
        $this->MaintainVariable('SavingsCost', $this->Translate('Projected saving in money'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], 9, true);
        $this->MaintainVariable('Message', $this->Translate('Result'), VARIABLETYPE_STRING, $Value, 10, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 11, true);
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
            'yearTitle'   => $this->Translate('Saving goal this year'),
            'monthTitle'  => $this->Translate('Saving goal this month'),
            'toLastYear'  => $this->Translate('to last year'),
            'goal'        => $this->Translate('Goal'),
            'reached'     => $this->Translate('reached'),
            'onTrack'     => $this->Translate('On track'),
            'behind'      => $this->Translate('Behind the goal'),
            'adjusted'    => $this->Translate('weather-adjusted'),
            'so'          => $this->Translate('Consumption so far'),
            'last'        => $this->Translate('Same period last year'),
            'projected'   => $this->Translate('Projection for the whole period'),
            'targetValue' => $this->Translate('Target for the whole period'),
            'savings'     => $this->Translate('Projected saving in money'),
            'openHint'    => $this->Translate('Click or tap again: open the history'),
            'noRef'       => $this->Translate('No reference data for the same period last year yet'),
            'empty'       => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
