<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

/**
 * Witterungsbereinigter Verbrauch: Der Verbrauch einer Heizung (Gas, Wärme, Strom der Wärmepumpe) im Verhältnis zu den
 * Heizgradtagen nach VDI 3807. So zeigt der Vergleich mit dem Vorjahr, ob der Verbrauch wirklich sinkt oder nur der Winter milder war.
 */
class Heizgradtage extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    // Zeiträume: laufender Monat und laufendes Jahr bis gestern, jeweils gegen denselben Zeitraum im Vorjahr
    private const PERIODS = ['Month', 'LastYearMonth', 'Year', 'LastYearYear'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyFloat('RoomTemperature', 20.0);
        $this->RegisterPropertyFloat('HeatingLimit', 15.0);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterTimer('Calculate', 0, 'HGT_Calculate($_IPS[\'TARGET\']);');
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
        $this->maintainVariables($this->outputUnit($Sources), (float) ($Sources['Area'] ?? 0.0));

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
            $this->SetStatus($this->ReadPropertyInteger('TemperatureID') === 0 ? IS_INACTIVE : self::STATUS_INVALID_CONFIGURATION);
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
     * Berechnet Verbrauch, Heizgradtage und Verbrauch je Heizgradtag für die Zeiträume und schreibt die Variablen.
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
        $TemperatureID = $this->ReadPropertyInteger('TemperatureID');
        $Info = $this->unitInfoFor($SourceID, $Sources['Unit']);
        if ($ArchiveID === 0 || !AC_GetLoggingStatus($ArchiveID, $SourceID) || !AC_GetLoggingStatus($ArchiveID, $TemperatureID)) {
            $this->SendDebug(__FUNCTION__, 'Quelle oder Temperatur nicht archiviert', 0);
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return;
        }

        // Mit einem Heizwert wird der Verbrauch in kWh umgerechnet, zum Beispiel Pellets in kg mit 4,8 kWh/kg
        [$Factor, $Unit] = $this->conversion($Info, $Sources);

        $Results = [];
        foreach ($this->ranges() as $Key => [$Start, $End]) {
            $Consumption = $End > $Start ? $this->consumptionBetween($ArchiveID, $SourceID, $Info, $Start, $End, 1) * $Factor : 0.0;
            $DegreeDays = $this->degreeDaysBetween($ArchiveID, $TemperatureID, $Start, $End, $this->ReadPropertyFloat('RoomTemperature'), $this->ReadPropertyFloat('HeatingLimit'));
            $Results[$Key] = [
                'consumption'  => $Consumption,
                'degreeDays'   => $DegreeDays,
                'perDegreeDay' => $DegreeDays > 0 ? $Consumption / $DegreeDays : 0.0
            ];
            $this->SetValue($Key . '_Consumption', round($Consumption, 2));
            $this->SetValue($Key . '_DegreeDays', round($DegreeDays, 1));
            $this->SetValue($Key . '_PerDegreeDay', round($Results[$Key]['perDegreeDay'], 4));
            // Heizenergie je Quadratmeter für das Jahr und das Vorjahr, wenn die Verbrauchsquellen die Wohnfläche kennen
            $Area = (float) ($Sources['Area'] ?? 0.0);
            if ($Area > 0 && in_array($Key, ['Year', 'LastYearYear'], true)) {
                $Results[$Key]['perArea'] = $Consumption / $Area;
                $this->SetValue($Key . '_PerArea', round($Results[$Key]['perArea'], 2));
            }
        }

        $MonthChange = $this->change($Results['Month']['perDegreeDay'], $Results['LastYearMonth']['perDegreeDay']);
        $YearChange = $this->change($Results['Year']['perDegreeDay'], $Results['LastYearYear']['perDegreeDay']);
        $this->SetValue('MonthChange', $MonthChange);
        $this->SetValue('YearChange', $YearChange);

        $this->SetValue('Message', sprintf(
            $this->Translate('Year: %s %s per degree day (%s %% to last year)'),
            number_format($Results['Year']['perDegreeDay'], 3, '.', ''),
            $Unit,
            ($YearChange > 0 ? '+' : '') . number_format($YearChange, 0, '.', '')
        ));

        $Json = (string) json_encode([
            'unit'        => $Unit,
            'sourceId'    => $SourceID,
            'clickOpen'   => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'periods'     => $Results,
            'monthChange' => $MonthChange,
            'yearChange'  => $YearChange
        ]);
        $this->SetValue('Data', $Json);
        $this->UpdateVisualizationValue($this->tileMessage($Json));

        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Zeiträume als [Beginn, Ende), jeweils bis zum Beginn des heutigen Tages. Der laufende Tag ist nicht vollständig
     * und zählt nicht, damit der Vergleich mit dem Vorjahr gleich viele ganze Tage umfasst.
     */
    private function ranges(): array
    {
        $Today = new DateTimeImmutable('today');
        $End = $Today->getTimestamp();
        $LastYearEnd = $Today->sub(new DateInterval('P1Y'))->getTimestamp();
        $MonthStart = $Today->modify('first day of this month');
        $YearStart = $Today->setDate((int) $Today->format('Y'), 1, 1);
        return [
            'Month'         => [$MonthStart->getTimestamp(), $End],
            'LastYearMonth' => [$MonthStart->sub(new DateInterval('P1Y'))->getTimestamp(), $LastYearEnd],
            'Year'          => [$YearStart->getTimestamp(), $End],
            'LastYearYear'  => [$YearStart->sub(new DateInterval('P1Y'))->getTimestamp(), $LastYearEnd]
        ];
    }

    /**
     * Umrechnung des Verbrauchs: Mit einem Heizwert je Einheit (kWh) wird jede Menge in kWh umgerechnet. Bei einer Quelle,
     * die schon Energie misst (kWh, Wh, kW, W), gilt der Heizwert nicht.
     */
    private function conversion(array $Info, ?array $Sources): array
    {
        $Energy = $this->energyPerUnit($Sources);
        if ($Energy > 0 && $Info['Family'] !== 'energy') {
            return [$Energy, 'kWh'];
        }
        return [1.0, $Info['Out']];
    }

    /**
     * Einheit der Ausgabe: kWh mit Heizwert, sonst die Einheit der Quelle.
     */
    private function outputUnit(?array $Sources): string
    {
        if ($Sources === null) {
            return 'kWh';
        }
        $ID = $this->sourceID($Sources);
        $Info = ($ID > 0 && IPS_VariableExists($ID)) ? $this->unitInfoFor($ID, $Sources['Unit']) : $Sources['Unit'];
        return $this->conversion($Info, $Sources)[1];
    }

    private function change(float $Current, float $Previous): float
    {
        return $Previous > 0 ? round(($Current - $Previous) / $Previous * 100, 1) : 0.0;
    }

    /**
     * Quelle der Auswertung: die Heizung der Verbrauchsquellen.
     */
    private function sourceID(array $Sources): int
    {
        return (int) ($Sources['Heating']['ID'] ?? 0);
    }

    /**
     * Heizwert je Einheit aus den Verbrauchsquellen, 0 ohne Eintrag.
     */
    private function energyPerUnit(?array $Sources): float
    {
        return (float) ($Sources['Heating']['EnergyPerUnit'] ?? 0.0);
    }

    private function validate(array $Sources): string
    {
        $SourceID = $this->sourceID($Sources);
        if ($SourceID === 0) {
            return 'Heizung fehlt in den Verbrauchsquellen';
        }
        if (!IPS_VariableExists($SourceID)) {
            return 'Die Quelle existiert nicht';
        }
        $TemperatureID = $this->ReadPropertyInteger('TemperatureID');
        if ($TemperatureID === 0) {
            return 'Keine Temperaturvariable gewählt';
        }
        if (!IPS_VariableExists($TemperatureID)) {
            return 'Die Temperaturvariable existiert nicht';
        }
        if ($this->ReadPropertyFloat('RoomTemperature') <= $this->ReadPropertyFloat('HeatingLimit')) {
            return 'Die Raumtemperatur muss über der Heizgrenze liegen';
        }
        return '';
    }

    private function maintainVariables(string $Unit, float $Area): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Labels = [
            'Month'         => $this->Translate('Month'),
            'LastYearMonth' => $this->Translate('Month last year'),
            'Year'          => $this->Translate('Year'),
            'LastYearYear'  => $this->Translate('Year last year')
        ];
        $Position = 1;
        foreach (self::PERIODS as $Key) {
            $this->MaintainVariable($Key . '_Consumption', $Labels[$Key] . ' ' . $this->Translate('consumption'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit, 'DIGITS' => 2], $Position++, true);
            $this->MaintainVariable($Key . '_DegreeDays', $Labels[$Key] . ' ' . $this->Translate('degree days'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' Kd', 'DIGITS' => 1], $Position++, true);
            $this->MaintainVariable($Key . '_PerDegreeDay', $Labels[$Key] . ' ' . $this->Translate('per degree day'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit . '/Kd', 'DIGITS' => 3], $Position++, true);
        }
        foreach (['Year', 'LastYearYear'] as $Key) {
            $this->MaintainVariable($Key . '_PerArea', $Labels[$Key] . ' ' . $this->Translate('per m²'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $Unit . '/m²', 'DIGITS' => 2], $Position++, $Area > 0);
        }
        $Percent = $Value + ['SUFFIX' => ' %', 'DIGITS' => 1];
        $this->MaintainVariable('MonthChange', $this->Translate('Month') . ' ' . $this->Translate('change to last year'), VARIABLETYPE_FLOAT, $Percent, $Position++, true);
        $this->MaintainVariable('YearChange', $this->Translate('Year') . ' ' . $this->Translate('change to last year'), VARIABLETYPE_FLOAT, $Percent, $Position++, true);
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
        $Data['texts'] = [
            'perDegreeDay' => $this->Translate('per degree day'),
            'toLastYear'   => $this->Translate('to last year'),
            'month'        => $this->Translate('Month'),
            'year'         => $this->Translate('Year'),
            'thisPeriod'   => $this->Translate('This year'),
            'lastPeriod'   => $this->Translate('Last year'),
            'thisMonth'    => $this->Translate('This month'),
            'lastMonth'    => $this->Translate('Month last year'),
            'consumption'  => $this->Translate('Consumption'),
            'degreeDays'   => $this->Translate('Degree days'),
            'perArea'      => $this->Translate('per m²'),
            'openHint'     => $this->Translate('Click or tap again: open the history'),
            'empty'        => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
