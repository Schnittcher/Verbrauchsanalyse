<?php

declare(strict_types=1);

/**
 * Anbindung der Auswertungsmodule an den Splitter Verbrauchsquellen: Konfiguration beim Parent abfragen
 * und bei Änderungen neu aufbauen. Typisiert für IPSModuleStrict.
 *
 * Das Modul ruft in Create() registerSourceChild() und in ApplyChanges() registerSourceMessages() auf,
 * leitet FM_CONNECT und FM_DISCONNECT aus MessageSink() an scheduleReload() weiter und ruft in
 * RequestAction() handleSourceAction() auf. Im Formular ruft GetConfigurationForm() overrideVisibility() auf.
 *
 * Optional überschreibt die Instanz Werte der Verbrauchsquellen (Preis, Emissionsfaktor, Bewohner, Fläche, Warmwasser, Heizwert und Preis
 * der Heizung). Das gilt mit dem Haken "OverrideSources" und nur für Felder "Override...", die im form.json der Instanz stehen.
 */
trait SourceChild
{
    // Datenschnittstelle Kind -> Splitter (Anfragen). Die Gegenrichtung (Splitter -> Kind) steht beim Splitter.
    private const DATA_ID_TO_PARENT = '{EEE0155C-0EE0-44EB-9AE2-B9163F802FC8}';

    // Status der Instanz, wenn die Konfiguration, die Quelle oder der Splitter nicht passen
    private const STATUS_INVALID_CONFIGURATION = 201;
    private const STATUS_SOURCE_UNUSABLE = 202;
    private const STATUS_NO_SOURCES = 203;

    private ?array $OverrideNames = null;

    // Ersetzt das Medium der Instanz für einen Aufruf, zum Beispiel beim Verbrauchsbericht je Bericht der Liste
    private ?int $MediumOverride = null;

    public function ReceiveData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (is_array($Data) && ($Data['Event'] ?? '') === 'ConfigChanged') {
            $this->scheduleReload();
        }
        return '';
    }

    /**
     * Die aktuelle Zeit für die Timer. Die Symcon-Stubs der Tests verlangen diese Methode im Modul.
     */
    protected function getTime(): int
    {
        return time();
    }

    private function registerSourceChild(int $DefaultMedium = 1): void
    {
        $this->RegisterPropertyBoolean('OverrideSources', false);
        $this->RegisterPropertyFloat('OverridePrice', 0.30);
        $this->RegisterPropertyInteger('OverridePriceID', 0);
        $this->RegisterPropertyFloat('OverrideEmission', 380.0);
        $this->RegisterPropertyInteger('OverrideEmissionID', 0);
        $this->RegisterPropertyInteger('OverrideResidents', 0);
        $this->RegisterPropertyFloat('OverrideArea', 0.0);
        $this->RegisterPropertyBoolean('OverrideHotWater', false);
        $this->RegisterPropertyFloat('OverrideHeatingEnergy', 0.0);
        $this->RegisterPropertyFloat('OverrideHeatingPrice', 0.0);
        $this->RegisterPropertyFloat('OverrideHeatingEmission', 0.0);
        // Welches Medium des Splitters (1 Strom, 2 Wasser, 3 Gas) die Instanz auswertet
        $this->RegisterPropertyInteger('Medium', $DefaultMedium);
        $this->RegisterTimer('Reload', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Reload\', \'\');');
    }

    private function registerSourceMessages(): void
    {
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
    }

    /**
     * Baut die Instanz kurz verzögert neu auf. Der Aufruf kommt aus dem Datenfluss des Parents, dort würde ein
     * sofortiger Rückruf an den Parent dessen Sperre treffen. Der Timer löst das vom Datenfluss.
     */
    private function scheduleReload(): void
    {
        $this->SetTimerInterval('Reload', 500);
    }

    private function handleSourceAction(string $Ident, mixed $Value = null): bool
    {
        if ($Ident === 'FormOverride') {
            foreach ($this->overrideNames() as $Name) {
                $this->UpdateFormField($Name, 'visible', (bool) $Value);
            }
            return true;
        }
        if ($Ident !== 'Reload') {
            return false;
        }
        $this->SetTimerInterval('Reload', 0);
        $this->ApplyChanges();
        return true;
    }

    /**
     * Blendet eine Variable der Instanz aus (Auswertungsdaten, Hilfsvariablen).
     */
    private function hideVariable(string $Ident): void
    {
        $ID = @$this->GetIDForIdent($Ident);
        if ($ID > 0 && !IPS_GetObject($ID)['ObjectIsHidden']) {
            IPS_SetHidden($ID, true);
        }
    }

    /**
     * Das gewählte Medium ist im Splitter nicht eingerichtet: weder Verbraucher noch Hauptzähler (bei der Heizung die Heizungsvariable).
     */
    private function mediumEmpty(array $Sources): bool
    {
        return ($Sources['Consumers'] ?? []) === [] && (int) ($Sources['MainMeter'] ?? 0) === 0;
    }

    /**
     * Konfiguration des Mediums der Instanz im Splitter: Einheit, Gruppen, Verbraucher, Hauptzähler und Preis, dazu Heizung, Bewohner und Fläche. Null ohne aktiven Parent.
     */
    private function requestSources(): ?array
    {
        if (!$this->HasActiveParent()) {
            return null;
        }
        $Result = $this->SendDataToParent(json_encode(['DataID' => self::DATA_ID_TO_PARENT, 'Action' => 'GetConfig', 'Medium' => $this->MediumOverride ?? $this->ReadPropertyInteger('Medium')]));
        $Config = json_decode($Result, true);
        if (!is_array($Config)) {
            return null;
        }
        return $this->ReadPropertyBoolean('OverrideSources') ? $this->applyOverrides($Config) : $Config;
    }

    /**
     * Die Felder "Override..." im form.json der Instanz, ohne den Haken selbst.
     *
     * @return array<int, string>
     */
    private function overrideNames(): array
    {
        if ($this->OverrideNames === null) {
            $Form = json_decode((string) file_get_contents(dirname((string) (new ReflectionClass($this))->getFileName()) . '/form.json'), true);
            $Names = [];
            $Walk = function (array $Items) use (&$Walk, &$Names): void
            {
                foreach ($Items as $Item) {
                    $Name = (string) ($Item['name'] ?? '');
                    if (str_starts_with($Name, 'Override') && $Name !== 'OverrideSources') {
                        $Names[] = $Name;
                    }
                    if (isset($Item['items']) && is_array($Item['items'])) {
                        $Walk($Item['items']);
                    }
                }
            };
            $Walk(is_array($Form) ? ($Form['elements'] ?? []) : []);
            $this->OverrideNames = $Names;
        }
        return $this->OverrideNames;
    }

    /**
     * Ersetzt Werte der Konfiguration durch die der Instanz. Nur Felder, die das Formular der Instanz anbietet.
     */
    private function applyOverrides(array $Config): array
    {
        $Has = array_flip($this->overrideNames());
        if (isset($Has['OverridePrice'])) {
            $Fixed = $this->ReadPropertyFloat('OverridePrice');
            $PriceID = $this->ReadPropertyInteger('OverridePriceID');
            $PriceID = ($PriceID > 0 && IPS_VariableExists($PriceID)) ? $PriceID : 0;
            $Now = $Fixed;
            if ($PriceID > 0) {
                $Now = (float) GetValue($PriceID) * $this->priceFactor($PriceID);
            }
            $Config['FixedPrice'] = $Fixed;
            $Config['PriceID'] = $PriceID;
            $Config['PriceNow'] = $Now;
        }
        if (isset($Has['OverrideEmission'])) {
            $EmissionID = $this->ReadPropertyInteger('OverrideEmissionID');
            $Config['EmissionFactor'] = $this->ReadPropertyFloat('OverrideEmission');
            $Config['EmissionID'] = ($EmissionID > 0 && IPS_VariableExists($EmissionID)) ? $EmissionID : 0;
        }
        if (isset($Has['OverrideResidents'])) {
            $Config['Residents'] = max(0, $this->ReadPropertyInteger('OverrideResidents'));
        }
        if (isset($Has['OverrideArea'])) {
            $Config['Area'] = max(0.0, $this->ReadPropertyFloat('OverrideArea'));
        }
        if (isset($Has['OverrideHotWater'])) {
            $Config['HotWaterElectric'] = $this->ReadPropertyBoolean('OverrideHotWater');
        }
        if (is_array($Config['Heating'] ?? null)) {
            if (isset($Has['OverrideHeatingEnergy'])) {
                $Config['Heating']['EnergyPerUnit'] = max(0.0, $this->ReadPropertyFloat('OverrideHeatingEnergy'));
            }
            if (isset($Has['OverrideHeatingPrice'])) {
                $Config['Heating']['Price'] = max(0.0, $this->ReadPropertyFloat('OverrideHeatingPrice'));
            }
            if (isset($Has['OverrideHeatingEmission'])) {
                $Config['Heating']['EmissionFactor'] = max(0.0, $this->ReadPropertyFloat('OverrideHeatingEmission'));
            }
        }
        return $Config;
    }

    /**
     * Blendet die Felder "Override..." im Formular nur mit gesetztem Haken ein.
     */
    private function overrideVisibility(array &$Form): void
    {
        $Show = $this->ReadPropertyBoolean('OverrideSources');
        $Apply = function (array &$Items) use (&$Apply, $Show): void
        {
            foreach ($Items as &$Item) {
                $Name = (string) ($Item['name'] ?? '');
                if (str_starts_with($Name, 'Override') && $Name !== 'OverrideSources') {
                    $Item['visible'] = $Show;
                }
                if (isset($Item['items']) && is_array($Item['items'])) {
                    $Apply($Item['items']);
                }
            }
        };
        $Apply($Form['elements']);
    }

    /**
     * Das Standardformular der Instanz mit eingeblendeten Überschreibungsfeldern, für Module ohne eigenes GetConfigurationForm.
     */
    private function sourceConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(dirname((string) (new ReflectionClass($this))->getFileName()) . '/form.json'), true);
        if (!is_array($Form)) {
            return '{}';
        }
        $this->overrideVisibility($Form);
        return (string) json_encode($Form);
    }
}
