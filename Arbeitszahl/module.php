<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

/**
 * Arbeitszahl und Wirkungsgrad: Verhältnis von Ertrag zu Einsatz zweier Zähler, zum Beispiel Wärme zu Strom bei einer
 * Wärmepumpe (Jahresarbeitszahl) oder Wärme zu Brennstoff bei einem Kessel (Wirkungsgrad). Der Einsatz kommt aus den
 * Verbrauchsquellen, der Ertrag aus einer eigenen Zählervariable.
 */
class Arbeitszahl extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    // Zeiträume: laufender Monat und laufendes Jahr, das Vorjahr über dieselbe Dauer und die letzten 365 Tage
    private const PERIODS = ['Month', 'Year', 'LastYearYear', 'Last12'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('OverrideSourceID', 0);
        $this->RegisterPropertyInteger('OutputID', 0);
        $this->RegisterPropertyFloat('OverrideInputEnergy', 0.0);
        $this->RegisterPropertyString('Mode', 'factor');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);

        $this->RegisterTimer('Calculate', 0, 'AZL_Calculate($_IPS[\'TARGET\']);');
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
            $this->SetStatus($this->outputID($Sources) === 0 ? IS_INACTIVE : self::STATUS_INVALID_CONFIGURATION);
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
     * Berechnet Einsatz, Ertrag und Arbeitszahl für die Zeiträume und die letzten zwölf Monate und schreibt die Variablen.
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
        $InputID = $this->sourceID($Sources);
        $OutputID = $this->outputID($Sources);
        if ($ArchiveID === 0 || !AC_GetLoggingStatus($ArchiveID, $InputID) || !AC_GetLoggingStatus($ArchiveID, $OutputID)) {
            $this->SendDebug(__FUNCTION__, 'Einsatz oder Ertrag nicht archiviert', 0);
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        $InputInfo = $this->unitInfoFor($InputID, $Sources['Unit']);
        $OutputInfo = $this->unitInfoFor($OutputID, $this->describeUnit('kWh'));
        // Brennstoffe wie Pellets in kg werden mit dem Heizwert in kWh umgerechnet
        $InputFactor = $InputInfo['Family'] === 'energy' ? 1.0 : $this->inputEnergyPerUnit($Sources, $InputID);

        $Percent = $this->ReadPropertyString('Mode') === 'percent';
        $Scale = $Percent ? 100.0 : 1.0;
        $Measure = function (int $Start, int $End) use ($ArchiveID, $InputID, $OutputID, $InputInfo, $OutputInfo, $InputFactor, $Scale): array
        {
            $In = $End > $Start ? $this->consumptionBetween($ArchiveID, $InputID, $InputInfo, $Start, $End, 1) * $InputFactor : 0.0;
            $Out = $End > $Start ? $this->consumptionBetween($ArchiveID, $OutputID, $OutputInfo, $Start, $End, 1) : 0.0;
            return ['input' => $In, 'output' => $Out, 'ratio' => $In > 0 ? $Out / $In * $Scale : 0.0];
        };

        // Zeiträume bis gestern, damit nur ganze Tage zählen
        $Today = new DateTimeImmutable('today');
        $End = $Today->getTimestamp();
        $LastYearEnd = $Today->sub(new DateInterval('P1Y'))->getTimestamp();
        $YearStart = $Today->setDate((int) $Today->format('Y'), 1, 1);
        $Ranges = [
            'Month'        => [$Today->modify('first day of this month')->getTimestamp(), $End],
            'Year'         => [$YearStart->getTimestamp(), $End],
            'LastYearYear' => [$YearStart->sub(new DateInterval('P1Y'))->getTimestamp(), $LastYearEnd],
            'Last12'       => [$LastYearEnd, $End]
        ];

        $Results = [];
        foreach ($Ranges as $Key => [$From, $To]) {
            $Results[$Key] = $Measure($From, $To);
            $this->SetValue($Key . '_Input', round($Results[$Key]['input'], 1));
            $this->SetValue($Key . '_Output', round($Results[$Key]['output'], 1));
            $this->SetValue($Key . '_Ratio', round($Results[$Key]['ratio'], $Percent ? 1 : 2));
        }

        // Verlauf der letzten zwölf Monate für die Kachel, der laufende Monat bis gestern
        $Months = [];
        $First = $Today->modify('first day of this month');
        for ($Back = 11; $Back >= 0; $Back--) {
            $From = $First->modify('-' . $Back . ' months');
            $To = min($From->modify('first day of next month')->getTimestamp(), $End);
            $Row = $Measure($From->getTimestamp(), $To);
            $Months[] = ['month' => $From->format('Y-m'), 'ratio' => round($Row['ratio'], 2), 'input' => round($Row['input'], 1), 'output' => round($Row['output'], 1)];
        }

        $Last12 = $Results['Last12']['ratio'];
        $this->SetValue('Message', sprintf(
            $this->Translate('Last 12 months: %s (input %s kWh, output %s kWh)'),
            number_format($Last12, $Percent ? 1 : 2, '.', '') . ($Percent ? ' %' : ''),
            number_format($Results['Last12']['input'], 0, '.', ''),
            number_format($Results['Last12']['output'], 0, '.', '')
        ));

        $Json = (string) json_encode([
            'percent'   => $Percent,
            'periods'   => $Results,
            'months'    => $Months,
            'sourceId'  => $InputID,
            'outputId'  => $OutputID,
            'clickOpen' => $this->ReadPropertyBoolean('OpenChartOnClick')
        ]);
        $this->SetValue('Data', $Json);
        $this->UpdateVisualizationValue($this->tileMessage($Json));

        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Einsatz der Auswertung: die eigene Auswahl, sonst der Hauptzähler des Splitters.
     */
    private function sourceID(array $Sources): int
    {
        $Own = $this->ReadPropertyBoolean('OverrideSources') ? $this->ReadPropertyInteger('OverrideSourceID') : 0;
        return $Own > 0 ? $Own : (int) ($Sources['MainMeter'] ?? 0);
    }

    /**
     * Ertrag: der eigene Zähler der Instanz, sonst der Wärmemengenzähler der Heizung aus den Verbrauchsquellen.
     */
    private function outputID(array $Sources): int
    {
        $Own = $this->ReadPropertyInteger('OutputID');
        return $Own > 0 ? $Own : (int) ($Sources['Heating']['HeatMeterID'] ?? 0);
    }

    private function validate(array $Sources): string
    {
        $InputID = $this->sourceID($Sources);
        if ($InputID === 0) {
            return 'Kein Einsatz: Im Splitter fehlt ein Hauptzähler und hier ist keine Quelle gewählt';
        }
        if (!IPS_VariableExists($InputID)) {
            return 'Der Einsatz existiert nicht';
        }
        $OutputID = $this->outputID($Sources);
        if ($OutputID === 0) {
            return 'Kein Ertrag gewählt';
        }
        if (!IPS_VariableExists($OutputID)) {
            return 'Der Ertrag existiert nicht';
        }
        $OutputInfo = $this->unitInfoFor($OutputID, $this->describeUnit('kWh'));
        if ($OutputInfo['Family'] !== 'energy') {
            return 'Der Ertrag muss in kWh, Wh oder MWh gemessen werden';
        }
        $InputInfo = $this->unitInfoFor($InputID, $Sources['Unit']);
        if ($InputInfo['Family'] !== 'energy' && $this->inputEnergyPerUnit($Sources, $InputID) <= 0) {
            return 'Der Einsatz ist keine Energie, der Heizwert je Einheit fehlt';
        }
        return '';
    }

    /**
     * Heizwert des Einsatzes in kWh je Einheit: Mit dem Haken zum Überschreiben gilt der eigene Wert, sonst bei der Heizung der Verbrauchsquellen deren Wert.
     */
    private function inputEnergyPerUnit(array $Sources, int $InputID): float
    {
        if ($this->ReadPropertyBoolean('OverrideSources') && $this->ReadPropertyFloat('OverrideInputEnergy') > 0) {
            return $this->ReadPropertyFloat('OverrideInputEnergy');
        }
        $Heating = $Sources['Heating'] ?? null;
        if (is_array($Heating) && (int) ($Heating['ID'] ?? 0) === $InputID && (float) ($Heating['EnergyPerUnit'] ?? 0) > 0) {
            return (float) $Heating['EnergyPerUnit'];
        }
        return 0.0;
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Percent = $this->ReadPropertyString('Mode') === 'percent';
        $Ratio = $Value + ['SUFFIX' => $Percent ? ' %' : '', 'DIGITS' => $Percent ? 1 : 2];
        $Labels = [
            'Month'        => $this->Translate('Month'),
            'Year'         => $this->Translate('Year'),
            'LastYearYear' => $this->Translate('Year last year'),
            'Last12'       => $this->Translate('Last 12 months')
        ];
        $Position = 1;
        foreach (self::PERIODS as $Key) {
            $this->MaintainVariable($Key . '_Ratio', $Labels[$Key] . ' ' . ($Percent ? $this->Translate('efficiency') : $this->Translate('performance factor')), VARIABLETYPE_FLOAT, $Ratio, $Position++, true);
            $this->MaintainVariable($Key . '_Input', $Labels[$Key] . ' ' . $this->Translate('input'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 1], $Position++, true);
            $this->MaintainVariable($Key . '_Output', $Labels[$Key] . ' ' . $this->Translate('output'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 1], $Position++, true);
        }
        $this->MaintainVariable('Message', $this->Translate('Result'), VARIABLETYPE_STRING, $Value, $Position++, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, $Position, true);
        $this->hideVariable('Data');
    }

    /**
     * Daten und übersetzte Texte für die Kachel. Die module.html enthält keine Texte einer Sprache.
     */
    private function tileMessage(string $Json): string
    {
        $Data = json_decode($Json, true);
        $Data = is_array($Data) ? $Data : [];
        $Percent = ($Data['percent'] ?? false) === true;
        $Data['texts'] = [
            'title'     => $Percent ? $this->Translate('Efficiency') : $this->Translate('Performance factor'),
            'last12'    => $this->Translate('Last 12 months'),
            'year'      => $this->Translate('Year'),
            'lastYear'  => $this->Translate('Year last year'),
            'month'     => $this->Translate('Month'),
            'input'     => $this->Translate('Input'),
            'output'    => $this->Translate('Output'),
            'perMonth'  => $this->Translate('per month'),
            'openHint'  => $this->Translate('Click or tap again: open the history'),
            'empty'     => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
