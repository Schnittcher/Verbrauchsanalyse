<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';

/**
 * Austauschrechner: Lohnt sich ein neues, sparsameres Gerät? Der Verbrauch des alten Geräts kommt aus dem Archiv
 * (oder aus einer Angabe in Watt), der des neuen aus dem Energielabel (kWh pro Jahr) oder aus Watt und Laufzeit.
 * Der Strompreis kommt aus den Verbrauchsquellen. Eine Instanz je Gerät. Die Eingaben lassen sich auch in der Kachel
 * ändern, die Angabe zum alten Gerät nur dann, wenn keine Archivvariable gesetzt ist.
 */
class Austauschrechner extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;

    // So viele ganze Tage vor heute messen den Verbrauch des alten Geräts
    private const MEASURE_DAYS = 30;

    // Eingaben, die die Kachel ändern darf, mit Grenzen
    private const EDITABLE = [
        'OldWatt'       => [0.0, 100000.0],
        'RuntimeHours'  => [0.1, 24.0],
        'NewKWhYear'    => [0.0, 100000.0],
        'NewWatt'       => [0.0, 100000.0],
        'PurchasePrice' => [0.0, 1000000.0],
        'Lifetime'      => [1.0, 50.0],
        'PriceIncrease' => [0.0, 50.0]
    ];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('DeviceID', 0);
        $this->RegisterPropertyFloat('OldWatt', 0.0);
        $this->RegisterPropertyFloat('RuntimeHours', 24.0);
        $this->RegisterPropertyFloat('NewKWhYear', 0.0);
        $this->RegisterPropertyFloat('NewWatt', 0.0);
        $this->RegisterPropertyFloat('PurchasePrice', 0.0);
        $this->RegisterPropertyInteger('Lifetime', 12);
        $this->RegisterPropertyFloat('PriceIncrease', 0.0);
        $this->RegisterPropertyInteger('UpdateInterval', 360);

        $this->RegisterTimer('Calculate', 0, 'AUS_Calculate($_IPS[\'TARGET\']);');
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
            if ($this->hasInput()) {
                $this->LogMessage('Konfiguration ungültig: ' . $Error, KL_WARNING);
            }
            $this->SetTimerInterval('Calculate', 0);
            $this->SetStatus($this->hasInput() ? self::STATUS_INVALID_CONFIGURATION : IS_INACTIVE);
            // Die Kachel bleibt bedienbar, damit sich die fehlenden Angaben dort eintragen lassen
            $this->publish(null);
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
        if ($this->handleSourceAction($Ident, $Value)) {
            return;
        }
        if ($Ident === 'Set') {
            $this->setFromTile((string) $Value);
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Rechnet Verbrauch, Einsparung, Amortisation und Gewinn über die Lebensdauer und schreibt die Variablen.
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

        $Old = $this->oldKWhPerYear();
        if ($Old === null) {
            $this->SendDebug(__FUNCTION__, 'Der Verbrauch des alten Geräts ist nicht ermittelbar', 0);
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            $this->publish(null);
            return;
        }

        $New = $this->newKWhPerYear();
        $Saving = $Old - $New;
        $Price = (float) $Sources['PriceNow'];
        $Growth = $this->ReadPropertyFloat('PriceIncrease') / 100;
        $Life = max(1, $this->ReadPropertyInteger('Lifetime'));
        $Purchase = $this->ReadPropertyFloat('PurchasePrice');
        $Yearly = $Saving * $Price;

        // Summe der Ersparnis über n Jahre bei jährlich steigendem Preis: Yearly * ((1+g)^n - 1) / g
        $Sum = fn (float $Years): float => abs($Growth) < 1e-9 ? $Yearly * $Years : $Yearly * (((1 + $Growth) ** $Years) - 1) / $Growth;
        $Payback = 0.0;
        if ($Yearly > 0) {
            $Payback = abs($Growth) < 1e-9
                ? $Purchase / $Yearly
                : log(1 + $Purchase * $Growth / $Yearly) / log(1 + $Growth);
        }
        $Net = $Sum((float) $Life) - $Purchase;

        if ($Yearly <= 0) {
            $State = 'none';
            $Verdict = $this->Translate('Not worth it: the new device saves no energy');
        } elseif ($Payback <= $Life) {
            $State = 'worth';
            $Verdict = sprintf(
                $this->Translate('Worth it: pays off after %s years, %s EUR gain over %d years'),
                number_format($Payback, 1, '.', ''),
                number_format($Net, 0, '.', ''),
                $Life
            );
        } else {
            $State = 'not';
            $Verdict = sprintf(
                $this->Translate('Not worth it: pays off only after %s years, the lifetime is %d years'),
                number_format($Payback, 1, '.', ''),
                $Life
            );
        }

        $this->SetValue('OldKWhYear', round($Old, 0));
        $this->SetValue('NewKWhYear', round($New, 0));
        $this->SetValue('SavingKWhYear', round($Saving, 0));
        $this->SetValue('SavingCostYear', round($Yearly, 2));
        $this->SetValue('PaybackYears', round($Payback, 1));
        $this->SetValue('NetLifetime', round($Net, 0));
        $this->SetValue('Verdict', $Verdict);
        $this->SetStatus(IS_ACTIVE);

        $this->publish([
            'state'    => $State,
            'verdict'  => $Verdict,
            'old'      => round($Old, 1),
            'new'      => round($New, 1),
            'saving'   => round($Saving, 1),
            'yearly'   => round($Yearly, 2),
            'payback'  => round($Payback, 2),
            'net'      => round($Net, 0),
            'price'    => round($Price, 4)
        ]);
    }

    /**
     * Schickt die Eingaben und, wenn vorhanden, das Ergebnis an die Kachel und legt sie für den nächsten Start ab.
     */
    private function publish(?array $Result): void
    {
        $Data = [
            'valid'       => $Result !== null,
            'result'      => $Result,
            'fromArchive' => $this->deviceID() > 0,
            'inputs'      => [
                'OldWatt'       => $this->ReadPropertyFloat('OldWatt'),
                'RuntimeHours'  => $this->ReadPropertyFloat('RuntimeHours'),
                'NewKWhYear'    => $this->ReadPropertyFloat('NewKWhYear'),
                'NewWatt'       => $this->ReadPropertyFloat('NewWatt'),
                'PurchasePrice' => $this->ReadPropertyFloat('PurchasePrice'),
                'Lifetime'      => $this->ReadPropertyInteger('Lifetime'),
                'PriceIncrease' => $this->ReadPropertyFloat('PriceIncrease')
            ]
        ];
        $Json = (string) json_encode($Data);
        $this->SetValue('Data', $Json);
        $this->UpdateVisualizationValue($this->tileMessage($Json));
    }

    /**
     * Übernimmt eine Eingabe aus der Kachel als Einstellung der Instanz. Nur bekannte Felder mit Zahlen in den Grenzen,
     * die Angabe zum alten Gerät nur ohne Archivvariable.
     */
    private function setFromTile(string $Json): void
    {
        $Input = json_decode($Json, true);
        $Key = is_array($Input) ? (string) ($Input['key'] ?? '') : '';
        $Value = is_array($Input) ? ($Input['value'] ?? null) : null;
        if (!isset(self::EDITABLE[$Key]) || !is_numeric($Value)) {
            $this->SendDebug(__FUNCTION__, 'Eingabe abgelehnt: ' . $Json, 0);
            return;
        }
        if ($Key === 'OldWatt' && $this->deviceID() > 0) {
            $this->SendDebug(__FUNCTION__, 'Die Leistung des alten Geräts kommt aus dem Archiv', 0);
            return;
        }
        [$Min, $Max] = self::EDITABLE[$Key];
        $Number = max($Min, min($Max, (float) $Value));
        if ($Key === 'Lifetime') {
            IPS_SetProperty($this->InstanceID, $Key, (int) round($Number));
        } else {
            IPS_SetProperty($this->InstanceID, $Key, $Number);
        }
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Verbrauch des alten Geräts in kWh pro Jahr: gemessen aus dem Archiv (Durchschnitt der letzten ganzen Tage, hochgerechnet)
     * oder aus der Angabe in Watt mal Laufzeit. Null, wenn beides nicht geht.
     */
    private function oldKWhPerYear(): ?float
    {
        $DeviceID = $this->deviceID();
        if ($DeviceID > 0 && IPS_VariableExists($DeviceID)) {
            $ArchiveID = $this->getArchiveID();
            if ($ArchiveID > 0 && AC_GetLoggingStatus($ArchiveID, $DeviceID)) {
                $Info = $this->unitInfoFor($DeviceID, $this->describeUnit('kWh'));
                if ($Info['Family'] === 'energy') {
                    $End = (new DateTimeImmutable('today'))->getTimestamp();
                    $Start = (new DateTimeImmutable('today'))->modify('-' . self::MEASURE_DAYS . ' days')->getTimestamp();
                    $Series = $this->hourlySeries($ArchiveID, $DeviceID, $Info, $Start, $End);
                    // Mindestens eine Woche an Stundenwerten, sonst gilt die Angabe in Watt
                    if ($Series !== null && count($Series) >= 7 * 24) {
                        return array_sum($Series) / (count($Series) / 24) * 365;
                    }
                }
            }
        }
        $Watt = $this->ReadPropertyFloat('OldWatt');
        return $Watt > 0 ? $Watt * $this->ReadPropertyFloat('RuntimeHours') * 365 / 1000 : null;
    }

    /**
     * Verbrauch des neuen Geräts in kWh pro Jahr: das Energielabel, sonst Watt mal Laufzeit.
     */
    private function newKWhPerYear(): float
    {
        $Label = $this->ReadPropertyFloat('NewKWhYear');
        return $Label > 0 ? $Label : $this->ReadPropertyFloat('NewWatt') * $this->ReadPropertyFloat('RuntimeHours') * 365 / 1000;
    }

    // Eine gelöschte Variable gilt als nicht gesetzt, damit die Instanz mit der Leistung in Watt weiterrechnet
    private function deviceID(): int
    {
        $ID = $this->ReadPropertyInteger('DeviceID');
        return ($ID > 0 && IPS_VariableExists($ID)) ? $ID : 0;
    }

    private function hasInput(): bool
    {
        return $this->deviceID() > 0 || $this->ReadPropertyFloat('OldWatt') > 0;
    }

    private function validate(array $Sources): string
    {
        if (!$this->hasInput()) {
            return 'Weder ein altes Gerät im Archiv noch eine Leistung in Watt angegeben';
        }
        if ($this->ReadPropertyFloat('NewKWhYear') <= 0 && $this->ReadPropertyFloat('NewWatt') <= 0) {
            return 'Für das neue Gerät fehlt das Energielabel oder die Leistung';
        }
        $RuntimeHours = $this->ReadPropertyFloat('RuntimeHours');
        if ($RuntimeHours <= 0 || $RuntimeHours > 24) {
            return 'Die Laufzeit muss zwischen 0 und 24 Stunden am Tag liegen';
        }
        if (($Sources['Unit']['Family'] ?? '') !== 'energy') {
            return 'Die Verbrauchsquellen rechnen nicht in Energie, der Preis je kWh fehlt';
        }
        return '';
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Energy = $Value + ['SUFFIX' => ' kWh', 'DIGITS' => 0];
        $this->MaintainVariable('OldKWhYear', $this->Translate('Old device per year'), VARIABLETYPE_FLOAT, $Energy, 1, true);
        $this->MaintainVariable('NewKWhYear', $this->Translate('New device per year'), VARIABLETYPE_FLOAT, $Energy, 2, true);
        $this->MaintainVariable('SavingKWhYear', $this->Translate('Saving per year'), VARIABLETYPE_FLOAT, $Energy, 3, true);
        $this->MaintainVariable('SavingCostYear', $this->Translate('Saving per year in money'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], 4, true);
        $this->MaintainVariable('PaybackYears', $this->Translate('Payback period'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' ' . $this->Translate('years'), 'DIGITS' => 1], 5, true);
        $this->MaintainVariable('NetLifetime', $this->Translate('Gain over the lifetime'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 0], 6, true);
        $this->MaintainVariable('Verdict', $this->Translate('Result'), VARIABLETYPE_STRING, $Value, 7, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 8, true);
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
            'worth'         => $this->Translate('Worth it'),
            'not'           => $this->Translate('Not worth it'),
            'none'          => $this->Translate('The new device saves no energy'),
            'payback'       => $this->Translate('Payback period'),
            'years'         => $this->Translate('years'),
            'never'         => $this->Translate('never'),
            'lifetime'      => $this->Translate('Expected lifetime'),
            'gain'          => $this->Translate('Gain over the lifetime'),
            'old'           => $this->Translate('Old device per year'),
            'new'           => $this->Translate('New device per year'),
            'savingYear'    => $this->Translate('Saving per year in money'),
            'savingKWh'     => $this->Translate('Saving per year'),
            'inputs'        => $this->Translate('Inputs'),
            'fromArchive'   => $this->Translate('Measured from the archive'),
            'incomplete'    => $this->Translate('Enter the missing values below'),
            'OldWatt'       => $this->Translate('Old device: average power while running'),
            'RuntimeHours'  => $this->Translate('Running hours per day'),
            'NewKWhYear'    => $this->Translate('New device: energy label (kWh per year, 0 = use the power below)'),
            'NewWatt'       => $this->Translate('New device: average power while running'),
            'PurchasePrice' => $this->Translate('Purchase price'),
            'Lifetime'      => $this->Translate('Expected lifetime'),
            'PriceIncrease' => $this->Translate('Expected price increase per year'),
            'empty'         => $this->Translate('No data yet')
        ];
        return (string) json_encode($Data);
    }
}
