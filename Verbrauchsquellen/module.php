<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/EmissionFactorsStrict.php';

/**
 * Splitter mit der gemeinsamen Quellenliste: Einheit, Preis, Verbraucher, Hauptzähler und Gruppen.
 * Top Verbraucher und Grundlast hängen als Kinder daran und fragen die Konfiguration per ForwardData ab.
 */
class Verbrauchsquellen extends IPSModuleStrict
{
    use ArchiveConsumption;
    use EmissionFactors;

    // Datenschnittstelle Splitter -> Kind (Benachrichtigungen). Die Gegenrichtung steht in libs/SourceChildStrict.php.
    private const DATA_ID_TO_CHILDREN = '{0F05D1E9-D61A-4BB7-A354-95233AA38306}';

    private const STATUS_INVALID_CONFIGURATION = 201;

    // Felder des Formulars, bei deren Änderung die Erkennungslabels neu berechnet werden
    private const INFO_FIELDS = ['PriceVariableID', 'PriceVariableID2', 'PriceVariableID3', 'PriceFactorMode', 'PriceFactorMode2', 'PriceFactorMode3', 'Currency', 'CurrencyCustom', 'CurrencyFromPrice'];

    private const MAX_GROUP_DEPTH = 3;

    // Die Medien des Splitters: 1 Strom, 2 Wasser, 3 Gas, je mit eigener Einheit, Preis, Verbraucherliste und Gruppen.
    // Medium 1 hat die Eigenschaften ohne Zusatz (Unit, Consumers ...), die anderen mit der Nummer als Zusatz (Unit2, Consumers2 ...).
    private const MEDIA = [1, 2, 3];
    // Das Medium Heizung (4) steht in ArchiveConsumption: Auswertungen, die es wählen, bekommen die Heizungsvariable als Hauptzähler
    // Erlaubte Einheiten je Medium
    private const MEDIUM_UNITS = [1 => ['kWh', 'Wh', 'kW', 'W'], 2 => ['m³', 'l'], 3 => ['m³', 'kWh', 'Wh']];
    private const MEDIUM_DEFAULTS = [1 => ['kWh', 0.30, 380.0], 2 => ['m³', 4.0, 0.0], 3 => ['m³', 1.2, 2000.0]];

    private const ROLE_CONSUMER = 'Consumer';
    private const ROLE_MAIN_METER = 'MainMeter';
    private const ROLE_IGNORE = 'Ignore';

    // Energieträger der Heizung, wie ihn die Kinder bekommen (Auswahl im Formular: 0 = keine Angabe)
    private const CARRIERS = [0 => '', 1 => 'gas', 2 => 'oil', 3 => 'district', 4 => 'pellets', 5 => 'electric', 6 => 'other'];

    // Vorschlag der Rolle Hauptzähler, wenn der Name einen dieser Teile enthält
    private const MAIN_METER_HINTS = ['gesamt', 'zähler', 'zaehler', 'netz', 'total', 'grid'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        foreach (self::MEDIA as $Medium) {
            $Suffix = $this->suffix($Medium);
            $this->RegisterPropertyString('Unit' . $Suffix, self::MEDIUM_DEFAULTS[$Medium][0]);
            $this->RegisterPropertyFloat('PricePerUnit' . $Suffix, self::MEDIUM_DEFAULTS[$Medium][1]);
            $this->RegisterPropertyInteger('PriceVariableID' . $Suffix, 0);
            // 0 automatisch aus dem Suffix der Preisvariable, 1 Preis in der Hauptwährung, 2 Preis in der Unterwährung (Cent)
            $this->RegisterPropertyInteger('PriceFactorMode' . $Suffix, 0);
            $this->RegisterPropertyFloat('EmissionFactor' . $Suffix, self::MEDIUM_DEFAULTS[$Medium][2]);
            $this->RegisterPropertyInteger('EmissionVariableID' . $Suffix, 0);
            $this->RegisterPropertyString('Consumers' . $Suffix, '[]');
            $this->RegisterPropertyString('Groups' . $Suffix, '[]');
        }
        // Währung für alle Medien: Auswahl (Code oder CUSTOM mit eigenem Symbol), auf Wunsch aus den Preisvariablen erkannt
        $this->RegisterPropertyString('Currency', 'EUR');
        $this->RegisterPropertyString('CurrencyCustom', '');
        $this->RegisterPropertyBoolean('CurrencyFromPrice', false);
        $this->RegisterPropertyInteger('Residents', 0);
        $this->RegisterPropertyFloat('Area', 0.0);
        $this->RegisterPropertyBoolean('HotWaterElectric', false);
        $this->RegisterPropertyInteger('HeatingVariableID', 0);
        $this->RegisterPropertyInteger('HeatingCarrier', 0);
        $this->RegisterPropertyInteger('HeatingHeatMeterID', 0);
        $this->RegisterPropertyFloat('HeatingEnergyPerUnit', 0.0);
        $this->RegisterPropertyFloat('HeatingPrice', 0.0);
        $this->RegisterPropertyFloat('HeatingEmissionFactor', 0.0);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $Errors = [];
        $Empty = true;
        foreach (self::MEDIA as $Medium) {
            $Config = $this->readConfiguration($Medium);
            foreach ($Config['Errors'] as $Error) {
                $Errors[] = $Error;
            }
            $Empty = $Empty && $Config['Consumers'] === [] && $Config['MainMeter'] === 0 && $Config['Heating'] === null;
        }
        // Verschiedene Währungen in den Preisvariablen sind ein Fehler: Die Beträge ließen sich nicht zusammenrechnen
        foreach ($this->currentCurrencyState()['Errors'] as $Error) {
            $Errors[] = $Error;
        }
        $Errors = array_values(array_unique($Errors));
        if ($Errors !== []) {
            foreach ($Errors as $Error) {
                $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            }
            $this->LogMessage('Invalid configuration: ' . $Errors[0], KL_ERROR);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
        } elseif ($Empty) {
            $this->SetStatus(IS_INACTIVE);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        // Die Kinder bauen sich mit der neuen Konfiguration neu auf
        $this->SendDataToChildren(json_encode(['DataID' => self::DATA_ID_TO_CHILDREN, 'Event' => 'ConfigChanged']));
    }

    /**
     * Anfragen der Kinder. "GetConfig" liefert die geprüfte Konfiguration.
     */
    public function ForwardData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (is_array($Data) && ($Data['Action'] ?? '') === 'GetConfig') {
            return (string) json_encode($this->exportConfiguration((int) ($Data['Medium'] ?? 1)));
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'FindVariables':
            case 'FindVariables2':
            case 'FindVariables3':
                $this->findVariables((string) $Value, (int) substr($Ident, 13) ?: 1);
                break;
            case 'UpdateInfo':
                $this->updateInfoFields((string) $Value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
                break;
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($Form)) {
            return '{}';
        }

        // Erkennung als Label und Neuberechnung bei Änderung der betroffenen Felder
        $Input = $this->formInput();
        foreach ($this->infoTexts($Input) as $Name => $Text) {
            $this->setElementProperty($Form['elements'], $Name, 'caption', $Text);
        }
        $this->setElementProperty($Form['elements'], 'CurrencyCustom', 'visible', $Input['C'] === 'CUSTOM');
        foreach (self::INFO_FIELDS as $Name) {
            $this->setElementProperty($Form['elements'], $Name, 'onChange', "IPS_RequestAction(\$id, 'UpdateInfo', json_encode(['P1' => \$PriceVariableID, 'P2' => \$PriceVariableID2, 'P3' => \$PriceVariableID3, 'M1' => \$PriceFactorMode, 'M2' => \$PriceFactorMode2, 'M3' => \$PriceFactorMode3, 'C' => \$Currency, 'Custom' => \$CurrencyCustom, 'Auto' => \$CurrencyFromPrice]));");
        }

        foreach (self::MEDIA as $Medium) {
            $Suffix = $this->suffix($Medium);
            $Config = $this->readConfiguration($Medium);
            $GroupOptions = [['caption' => $this->Translate('No group'), 'value' => '']];
            foreach ($Config['Groups'] as $Group) {
                $GroupOptions[] = ['caption' => $Group['Name'], 'value' => $Group['Name']];
            }
            $this->setListValues($Form['elements'], 'Consumers' . $Suffix, $this->decorateConsumers($Config['RawConsumers']));
            $this->setListValues($Form['elements'], 'Groups' . $Suffix, $Config['RawGroups']);
            $this->setColumnOptions($Form['elements'], 'Consumers' . $Suffix, 'Group', $GroupOptions);
            $this->setColumnOptions($Form['elements'], 'Groups' . $Suffix, 'Parent', $GroupOptions);
        }

        return json_encode($Form);
    }

    // ---------------------------------------------------------------- Konfiguration

    /**
     * Die Konfiguration, wie sie die Kinder bekommen. Gruppen und Verbraucher als Listen, Preis in der Hauptwährung je Einheit.
     */
    private function exportConfiguration(int $Medium): array
    {
        if ($Medium === self::MEDIUM_HEATING) {
            return $this->exportHeating();
        }
        $Medium = in_array($Medium, self::MEDIA, true) ? $Medium : 1;
        $Config = $this->readConfiguration($Medium);
        return [
            'Unit'             => $Config['Unit'],
            'Groups'           => array_values($Config['Groups']),
            'Consumers'        => array_values($Config['Consumers']),
            'MainMeter'        => $Config['MainMeter'],
            'PriceID'          => $Config['PriceID'],
            'PriceFactorMode'  => $this->ReadPropertyInteger('PriceFactorMode' . $this->suffix($Medium)),
            'Currency'         => $this->currentCurrencyState()['Symbol'],
            'CurrencyCode'     => $this->currentCurrencyState()['Code'],
            'FixedPrice'       => $this->ReadPropertyFloat('PricePerUnit' . $this->suffix($Medium)),
            'PriceNow'         => $this->currentPrice($Config['PriceID'], $Medium),
            'EmissionFactor'   => $this->ReadPropertyFloat('EmissionFactor' . $this->suffix($Medium)),
            'EmissionID'       => $Config['EmissionID'],
            'Medium'           => $Medium,
            'Residents'        => max(0, $this->ReadPropertyInteger('Residents')),
            'Area'             => max(0.0, $this->ReadPropertyFloat('Area')),
            'HotWaterElectric' => $this->ReadPropertyBoolean('HotWaterElectric'),
            'Heating'          => $Config['Heating'],
            'Errors'           => $Config['Errors']
        ];
    }

    /**
     * Die Heizung als Medium: Die Heizungsvariable ist der Hauptzähler, die Einheit ist ihre eigene (sonst kWh), der Preis der Preis je Einheit
     * des Heizungsblocks und der Emissionsfaktor der Faktor je kWh mal Heizwert, also in g je Einheit. Ohne Heizung ist der Hauptzähler leer.
     */
    private function exportHeating(): array
    {
        $Config = $this->readConfiguration(1);
        $Heating = $Config['Heating'];
        $UnitText = $Heating !== null ? $this->getUnit($Heating['ID']) : '';
        $Unit = $this->describeUnit($UnitText !== '' ? $UnitText : 'kWh');
        $Factor = 0.0;
        if ($Heating !== null) {
            $PerUnit = $this->heatingEnergyPerUnit($Heating, $Unit);
            $Factor = $PerUnit === null ? 0.0 : $this->heatingFactor($Heating) * $PerUnit;
        }
        return [
            'Unit'             => $Unit,
            'Groups'           => [],
            'Consumers'        => [],
            'MainMeter'        => $Heating !== null ? (int) $Heating['ID'] : 0,
            'PriceID'          => 0,
            'PriceFactorMode'  => 0,
            'Currency'         => $this->currentCurrencyState()['Symbol'],
            'CurrencyCode'     => $this->currentCurrencyState()['Code'],
            'FixedPrice'       => $Heating !== null ? (float) $Heating['Price'] : 0.0,
            'PriceNow'         => $Heating !== null ? (float) $Heating['Price'] : 0.0,
            'EmissionFactor'   => $Factor,
            'EmissionID'       => 0,
            'Medium'           => self::MEDIUM_HEATING,
            'Residents'        => max(0, $this->ReadPropertyInteger('Residents')),
            'Area'             => max(0.0, $this->ReadPropertyFloat('Area')),
            'HotWaterElectric' => $this->ReadPropertyBoolean('HotWaterElectric'),
            'Heating'          => $Heating,
            'Errors'           => $Config['Errors']
        ];
    }

    /**
     * Aktueller Preis je Einheit in der Hauptwährung: Wert der Preisvariable (Cent und Ähnliches werden umgerechnet), sonst der feste Preis.
     */
    private function currentPrice(int $PriceID, int $Medium): float
    {
        if ($PriceID <= 0 || !IPS_VariableExists($PriceID)) {
            return $this->ReadPropertyFloat('PricePerUnit' . $this->suffix($Medium));
        }
        return (float) GetValue($PriceID) * $this->priceFactor($PriceID, $this->ReadPropertyInteger('PriceFactorMode' . $this->suffix($Medium)));
    }

    // ---------------------------------------------------------------- Währung

    /**
     * Die Währung nach den Einstellungen der Instanz, siehe currencyState().
     */
    private function currentCurrencyState(): array
    {
        $Input = $this->formInput();
        return $this->currencyState($Input['P'], $Input['C'], $Input['Custom'], $Input['Auto']);
    }

    /**
     * Preisvariablen, Faktor-Modus und Währungsauswahl, wie sie im Formular stehen (aus den Eigenschaften).
     */
    private function formInput(): array
    {
        $Input = ['P' => [], 'M' => [], 'C' => $this->ReadPropertyString('Currency'), 'Custom' => $this->ReadPropertyString('CurrencyCustom'), 'Auto' => $this->ReadPropertyBoolean('CurrencyFromPrice')];
        foreach (self::MEDIA as $Medium) {
            $Input['P'][$Medium] = $this->ReadPropertyInteger('PriceVariableID' . $this->suffix($Medium));
            $Input['M'][$Medium] = $this->ReadPropertyInteger('PriceFactorMode' . $this->suffix($Medium));
        }
        return $Input;
    }

    /**
     * Welche Währung gilt. Die Auswahl ist immer die Grundlage. Mit dem Haken gilt stattdessen die Währung aus den Preisvariablen,
     * wenn sie eindeutig ist. Erkennen die Preisvariablen verschiedene Währungen, ist das ein Fehler (Errors).
     *
     * @param array<int, int> $PriceIDs Preisvariable je Medium
     */
    private function currencyState(array $PriceIDs, string $Selection, string $Custom, bool $FromPrice): array
    {
        $Detected = [];
        foreach ($PriceIDs as $ID) {
            $Code = $this->detectPriceUnit((int) $ID)['Currency'];
            if ($Code !== null) {
                $Detected[$Code] = true;
            }
        }
        $Detected = array_keys($Detected);

        $Errors = [];
        $Custom = trim($Custom);
        if ($Selection === 'CUSTOM') {
            $Selected = $Custom;
            if ($Custom === '') {
                $Errors[] = 'Das eigene Währungssymbol fehlt';
            }
        } else {
            $Selected = isset(self::CURRENCIES[$Selection]) ? $Selection : 'EUR';
        }
        if (count($Detected) > 1) {
            $Errors[] = 'Die Preisvariablen haben verschiedene Währungen: ' . implode(', ', $Detected);
        }

        $FromVariable = $FromPrice && count($Detected) === 1;
        $Code = $FromVariable ? $Detected[0] : $Selected;
        $Code = $Code === '' ? 'EUR' : $Code;
        return ['Code' => $Code, 'Symbol' => $this->currencySymbol($Code), 'Selected' => $Selected, 'Detected' => $Detected, 'FromVariable' => $FromVariable, 'Errors' => $Errors];
    }

    /**
     * Die Texte der Erkennungslabels im Formular: Währung und je Medium der Faktor der Preisvariable.
     */
    private function infoTexts(array $Input): array
    {
        $State = $this->currencyState($Input['P'], $Input['C'], $Input['Custom'], $Input['Auto']);
        $Symbol = $State['Symbol'];
        if (count($State['Detected']) > 1) {
            $Currency = sprintf($this->Translate('Price variables use different currencies (%s). Use the same currency for all of them.'), implode(', ', $State['Detected']));
        } elseif ($State['FromVariable']) {
            $Currency = sprintf($this->Translate('Detected from the price variable: %s'), $Symbol);
        } elseif ($Input['Auto']) {
            $Currency = sprintf($this->Translate('Currency not clear from the price variables, using the selection: %s'), $Symbol);
        } elseif ($State['Detected'] !== [] && $State['Detected'][0] !== $State['Selected']) {
            $Currency = sprintf($this->Translate('Currency: %s. Note: the price variable shows %s.'), $Symbol, $this->currencySymbol($State['Detected'][0]));
        } else {
            $Currency = sprintf($this->Translate('Currency: %s'), $Symbol);
        }

        $Texts = ['CurrencyInfo' => $Currency];
        foreach (self::MEDIA as $Medium) {
            $ID = (int) $Input['P'][$Medium];
            $Mode = (int) $Input['M'][$Medium];
            $Name = 'PriceFactorInfo' . $this->suffix($Medium);
            if ($ID <= 0 || !IPS_VariableExists($ID)) {
                $Texts[$Name] = $this->Translate('No price variable, the fixed price is used.');
                continue;
            }
            $Detect = $this->detectPriceUnit($ID);
            $Suffix = $Detect['Suffix'] !== '' ? $Detect['Suffix'] : '-';
            if ($Mode === 1) {
                $Texts[$Name] = $this->Translate('Set manually: the price is used as it is.');
            } elseif ($Mode === 2) {
                $Texts[$Name] = $this->Translate('Set manually: the price is divided by 100.');
            } elseif ($Detect['Sub'] === true) {
                $Texts[$Name] = sprintf($this->Translate('Detected: %s, the price is divided by 100.'), $Suffix);
            } elseif ($Detect['Sub'] === false) {
                $Texts[$Name] = sprintf($this->Translate('Detected: %s, the price is used as it is.'), $Suffix);
            } else {
                $Texts[$Name] = sprintf($this->Translate('Not clear (%s), the price is used as it is. Set the factor manually if it is in cents.'), $Suffix);
            }
        }
        return $Texts;
    }

    /**
     * Berechnet die Erkennungslabels neu. Der Wert ist das JSON mit den aktuellen Formularwerten (noch nicht gespeichert).
     */
    private function updateInfoFields(string $Json): void
    {
        $Data = json_decode($Json, true);
        if (!is_array($Data)) {
            return;
        }
        $Input = [
            'P'      => [1 => (int) ($Data['P1'] ?? 0), 2 => (int) ($Data['P2'] ?? 0), 3 => (int) ($Data['P3'] ?? 0)],
            'M'      => [1 => (int) ($Data['M1'] ?? 0), 2 => (int) ($Data['M2'] ?? 0), 3 => (int) ($Data['M3'] ?? 0)],
            'C'      => (string) ($Data['C'] ?? 'EUR'),
            'Custom' => (string) ($Data['Custom'] ?? ''),
            'Auto'   => (bool) ($Data['Auto'] ?? false)
        ];
        foreach ($this->infoTexts($Input) as $Name => $Text) {
            $this->UpdateFormField($Name, 'caption', $Text);
        }
        $this->UpdateFormField('CurrencyCustom', 'visible', $Input['C'] === 'CUSTOM');
    }

    private function setElementProperty(array &$Elements, string $Name, string $Key, mixed $Value): void
    {
        foreach ($Elements as &$Element) {
            if (($Element['name'] ?? '') === $Name) {
                $Element[$Key] = $Value;
            } elseif (isset($Element['items'])) {
                $this->setElementProperty($Element['items'], $Name, $Key, $Value);
            }
        }
    }

    /**
     * Liest und prüft Verbraucher und Gruppen. Fehler verhindern die Auswertung, die Instanz meldet dann Status 201.
     */
    private function readConfiguration(int $Medium = 1): array
    {
        $Errors = [];
        $Suffix = $this->suffix($Medium);
        $Unit = $this->describeUnit($this->configuredUnit($Medium));
        if (!in_array($this->configuredUnit($Medium), self::MEDIUM_UNITS[$Medium] ?? [], true)) {
            $Errors[] = 'Die Einheit passt nicht zum Medium';
        }

        $RawConsumers = json_decode($this->ReadPropertyString('Consumers' . $Suffix), true);
        $RawConsumers = is_array($RawConsumers) ? $RawConsumers : [];
        $RawGroups = json_decode($this->ReadPropertyString('Groups' . $Suffix), true);
        $RawGroups = is_array($RawGroups) ? $RawGroups : [];

        // Gruppen
        $Groups = [];
        foreach ($RawGroups as $Row) {
            $Name = trim((string) ($Row['Name'] ?? ''));
            if ($Name === '') {
                $Errors[] = 'Eine Gruppe hat keinen Namen';
                continue;
            }
            if (isset($Groups[$Name])) {
                $Errors[] = 'Gruppe doppelt: ' . $Name;
                continue;
            }
            $Groups[$Name] = [
                'Name'    => $Name,
                'Icon'    => trim((string) ($Row['Icon'] ?? '')),
                'Color'   => (int) ($Row['Color'] ?? -1),
                'Parent'  => trim((string) ($Row['Parent'] ?? '')),
                'MeterID' => (int) ($Row['MeterID'] ?? 0)
            ];
        }
        foreach ($Groups as $Group) {
            if ($Group['Parent'] !== '' && !isset($Groups[$Group['Parent']])) {
                $Errors[] = 'Obergruppe von ' . $Group['Name'] . ' existiert nicht';
            }
            if ($Group['MeterID'] > 0 && !IPS_VariableExists($Group['MeterID'])) {
                $Errors[] = 'Gruppenzähler von ' . $Group['Name'] . ' existiert nicht';
            }
            $Depth = 1;
            $Walker = $Group;
            while ($Walker['Parent'] !== '' && isset($Groups[$Walker['Parent']]) && $Depth <= count($Groups)) {
                $Walker = $Groups[$Walker['Parent']];
                $Depth++;
            }
            if ($Depth > count($Groups)) {
                $Errors[] = 'Gruppen bilden einen Kreis: ' . $Group['Name'];
            } elseif ($Depth > self::MAX_GROUP_DEPTH) {
                $Errors[] = 'Gruppe ' . $Group['Name'] . ' liegt tiefer als ' . self::MAX_GROUP_DEPTH . ' Ebenen';
            }
        }

        $PriceID = $this->ReadPropertyInteger('PriceVariableID' . $Suffix);
        if ($PriceID > 0 && !IPS_VariableExists($PriceID)) {
            $Errors[] = 'Die Preisvariable existiert nicht';
        }

        $EmissionID = $this->ReadPropertyInteger('EmissionVariableID' . $Suffix);
        if ($EmissionID > 0 && !IPS_VariableExists($EmissionID)) {
            $Errors[] = 'Die Variable mit dem Emissionsfaktor existiert nicht';
        }
        if ($this->ReadPropertyFloat('EmissionFactor' . $Suffix) < 0 || $this->ReadPropertyFloat('HeatingEmissionFactor') < 0) {
            $Errors[] = 'Der Emissionsfaktor darf nicht negativ sein';
        }

        // Heizung (optional): eine Variable mit Energieträger, auf Wunsch Heizwert je Einheit und eigenem Preis
        $Heating = null;
        $HeatingID = $this->ReadPropertyInteger('HeatingVariableID');
        if ($HeatingID > 0) {
            if (!IPS_VariableExists($HeatingID)) {
                $Errors[] = 'Die Heizungsvariable existiert nicht';
            } elseif ($this->ReadPropertyInteger('HeatingHeatMeterID') > 0 && !IPS_VariableExists($this->ReadPropertyInteger('HeatingHeatMeterID'))) {
                $Errors[] = 'Der Wärmemengenzähler der Heizung existiert nicht';
            } elseif ($this->ReadPropertyFloat('HeatingEnergyPerUnit') < 0 || $this->ReadPropertyFloat('HeatingPrice') < 0) {
                $Errors[] = 'Heizwert und Preis der Heizung dürfen nicht negativ sein';
            } else {
                $Heating = [
                    'ID'             => $HeatingID,
                    'Carrier'        => self::CARRIERS[$this->ReadPropertyInteger('HeatingCarrier')] ?? '',
                    'EnergyPerUnit'  => $this->ReadPropertyFloat('HeatingEnergyPerUnit'),
                    'Price'          => $this->ReadPropertyFloat('HeatingPrice'),
                    'HeatMeterID'    => $this->ReadPropertyInteger('HeatingHeatMeterID'),
                    'EmissionFactor' => $this->ReadPropertyFloat('HeatingEmissionFactor'),
                    'Name'           => IPS_GetName($HeatingID)
                ];
            }
        }

        // Verbraucher
        $Consumers = [];
        $MainMeter = 0;
        $Seen = [];
        $Meters = array_column($Groups, 'MeterID');
        foreach ($RawConsumers as $Row) {
            $ID = (int) ($Row['VariableID'] ?? 0);
            $Role = (string) ($Row['Role'] ?? self::ROLE_CONSUMER);
            if ($ID <= 0 || !IPS_VariableExists($ID)) {
                continue;
            }
            if (isset($Seen[$ID])) {
                $Errors[] = 'Variable ' . $ID . ' kommt mehrfach vor';
                continue;
            }
            $Seen[$ID] = true;
            if ($Role === self::ROLE_IGNORE) {
                continue;
            }
            if ($Role === self::ROLE_MAIN_METER) {
                if ($MainMeter > 0) {
                    $Errors[] = 'Es darf nur einen Hauptzähler geben';
                }
                $MainMeter = $ID;
                continue;
            }
            $Group = trim((string) ($Row['Group'] ?? ''));
            if ($Group !== '' && !isset($Groups[$Group])) {
                $Errors[] = 'Gruppe ' . $Group . ' von Variable ' . $ID . ' existiert nicht';
                continue;
            }
            if (in_array($ID, $Meters, true)) {
                $Errors[] = 'Variable ' . $ID . ' ist Gruppenzähler und zugleich Verbraucher';
            }
            $Name = trim((string) ($Row['DisplayName'] ?? ''));
            $Consumers[$ID] = [
                'ID'    => $ID,
                'Group' => $Group,
                'Name'  => $Name !== '' ? $Name : IPS_GetName($ID)
            ];
        }

        return [
            'Unit'         => $Unit,
            'Groups'       => $Groups,
            'Consumers'    => $Consumers,
            'MainMeter'    => $MainMeter,
            'PriceID'      => $PriceID,
            'EmissionID'   => $EmissionID,
            'Heating'      => $Heating,
            'Errors'       => $Errors,
            'RawConsumers' => $RawConsumers,
            'RawGroups'    => $RawGroups
        ];
    }

    private function configuredUnit(int $Medium): string
    {
        $Suffix = $this->suffix($Medium);
        return $this->ReadPropertyString('Unit' . $Suffix);
    }

    /**
     * Zusatz der Eigenschaftsnamen eines Mediums: leer für Medium 1, sonst die Nummer.
     */
    private function suffix(int $Medium): string
    {
        return $Medium === 1 ? '' : (string) $Medium;
    }

    // ---------------------------------------------------------------- Formular

    private function findVariables(string $Json, int $Medium): void
    {
        $Rows = json_decode($Json, true);
        $Rows = is_array($Rows) ? $Rows : [];
        // Eine einzelne Zeile kommt als Objekt statt als Liste an
        if (array_key_exists('VariableID', $Rows)) {
            $Rows = [$Rows];
        }
        $Unit = $this->describeUnit($this->configuredUnit($Medium));

        $Known = [];
        $HasMain = false;
        foreach ($Rows as $Row) {
            $Known[(int) ($Row['VariableID'] ?? 0)] = true;
            $HasMain = $HasMain || ($Row['Role'] ?? '') === self::ROLE_MAIN_METER;
        }

        $Added = 0;
        foreach (IPS_GetVariableList() as $ID) {
            if (isset($Known[$ID]) || IPS_GetParent($ID) === $this->InstanceID) {
                continue;
            }
            if ($this->describeUnit($this->getUnit($ID))['Key'] !== $Unit['Key']) {
                continue;
            }
            $Role = self::ROLE_CONSUMER;
            if (!$HasMain && $this->looksLikeMainMeter(IPS_GetName($ID))) {
                $Role = self::ROLE_MAIN_METER;
                $HasMain = true;
            }
            $Rows[] = ['VariableID' => $ID, 'Role' => $Role, 'Group' => '', 'DisplayName' => ''];
            $Added++;
        }
        $this->SendDebug(__FUNCTION__, $Added . ' neue Variablen mit Einheit ' . $Unit['Key'] . ' gefunden', 0);

        $this->UpdateFormField('Consumers' . $this->suffix($Medium), 'values', json_encode($this->decorateConsumers($Rows)));
    }

    private function looksLikeMainMeter(string $Name): bool
    {
        $Name = mb_strtolower($Name);
        foreach (self::MAIN_METER_HINTS as $Hint) {
            if (str_contains($Name, $Hint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ergänzt die Anzeigespalten (Pfad, Archiv, Wert) und sortiert: Konfigurierte zuerst, danach nach Pfad.
     */
    private function decorateConsumers(array $Rows): array
    {
        $ArchiveID = $this->getArchiveID();
        $Result = [];
        foreach ($Rows as $Row) {
            if (!is_array($Row)) {
                continue;
            }
            $ID = (int) ($Row['VariableID'] ?? 0);
            $Exists = $ID > 0 && IPS_VariableExists($ID);
            $Row['VariableID'] = $ID;
            $Row['Role'] = (string) ($Row['Role'] ?? self::ROLE_CONSUMER);
            $Row['Group'] = (string) ($Row['Group'] ?? '');
            $Row['DisplayName'] = (string) ($Row['DisplayName'] ?? '');
            $Row['Path'] = $Exists ? IPS_GetLocation($ID) : '-';
            $Row['Archive'] = '-';
            $Row['Value'] = '-';
            if ($Exists) {
                if ($ArchiveID > 0) {
                    $Row['Archive'] = AC_GetLoggingStatus($ArchiveID, $ID) ? $this->Translate('Yes') : $this->Translate('No');
                }
                $Row['Value'] = GetValueFormatted($ID);
            }
            if ($Exists && $Row['Archive'] === $this->Translate('No')) {
                $Row['rowColor'] = '#808080';
            }
            $Result[] = $Row;
        }
        usort($Result, function (array $A, array $B): int
        {
            $Active = fn (array $Row): int => $Row['Role'] === self::ROLE_IGNORE ? 1 : 0;
            return [$Active($A), $A['Path']] <=> [$Active($B), $B['Path']];
        });
        return $Result;
    }

    private function setListValues(array &$Elements, string $Name, array $Values): void
    {
        foreach ($Elements as &$Element) {
            if (($Element['name'] ?? '') === $Name && ($Element['type'] ?? '') === 'List') {
                $Element['values'] = $Values;
            } elseif (isset($Element['items'])) {
                $this->setListValues($Element['items'], $Name, $Values);
            }
        }
    }

    private function setColumnOptions(array &$Elements, string $List, string $Column, array $Options): void
    {
        foreach ($Elements as &$Element) {
            if (($Element['name'] ?? '') === $List && ($Element['type'] ?? '') === 'List') {
                foreach ($Element['columns'] as &$Col) {
                    if ($Col['name'] === $Column) {
                        $Col['edit']['options'] = $Options;
                    }
                }
            } elseif (isset($Element['items'])) {
                $this->setColumnOptions($Element['items'], $List, $Column, $Options);
            }
        }
    }
}
