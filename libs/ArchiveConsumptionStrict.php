<?php

declare(strict_types=1);

/**
 * Gemeinsamer Archivcode der Module dieser Bibliothek: Einheiten, Archiv-Instanz und Verbrauch aus Aggregaten.
 * Typisiert für IPSModuleStrict.
 */
trait ArchiveConsumption
{
    private const ARCHIVE_MODULE_ID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // Einheit (klein geschrieben) => Familie, Faktor auf die Basiseinheit, Art. Leistung wird über die Zeit zu Energie integriert.
    private const UNITS = [
        'kwh' => ['energy', 1.0, 'counter'],
        'wh'  => ['energy', 0.001, 'counter'],
        'mwh' => ['energy', 1000.0, 'counter'],
        'kw'  => ['energy', 1.0, 'power'],
        'w'   => ['energy', 0.001, 'power'],
        'm³'  => ['volume', 1.0, 'counter'],
        'l'   => ['volume', 0.001, 'counter']
    ];

    /**
     * Das Medium der Heizung in den Verbrauchsquellen (1 bis 3 sind Strom, Wasser und Gas, siehe dort).
     */
    private const MEDIUM_HEATING = 4;

    // Währungen zur Auswahl: Code => Anzeigetext (Suffix der Variablen)
    private const CURRENCIES = ['EUR' => '€', 'CHF' => 'CHF', 'GBP' => '£', 'USD' => '$', 'SEK' => 'SEK', 'NOK' => 'NOK', 'DKK' => 'DKK', 'PLN' => 'PLN', 'CZK' => 'CZK'];

    // Suffix einer Preisvariable (vor dem "/", klein geschrieben, ohne Punkt) => [Währung oder null, Unterwährung]
    // Null als Währung heißt: aus dem Suffix nicht eindeutig ($ gilt für mehrere Länder, kr für drei).
    private const PRICE_UNITS = [
        '€'      => ['EUR', false], 'eur' => ['EUR', false], 'euro' => ['EUR', false],
        'ct'     => ['EUR', true], 'cent' => ['EUR', true], 'cents' => ['EUR', true],
        'chf'    => ['CHF', false], 'fr' => ['CHF', false], 'rp' => ['CHF', true], 'rappen' => ['CHF', true],
        '£'      => ['GBP', false], 'gbp' => ['GBP', false], 'p' => ['GBP', true], 'pence' => ['GBP', true],
        'usd'    => ['USD', false], '¢' => ['USD', true],
        '$'      => [null, false], 'kr' => [null, false], 'c' => [null, true],
        'øre'    => [null, true], 'öre' => [null, true], 'ore' => [null, true],
        'sek'    => ['SEK', false], 'nok' => ['NOK', false], 'dkk' => ['DKK', false],
        'pln'    => ['PLN', false], 'zł' => ['PLN', false], 'zl' => ['PLN', false], 'gr' => ['PLN', true],
        'czk'    => ['CZK', false], 'kč' => ['CZK', false]
    ];

    private function getArchiveID(): int
    {
        $IDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE_ID);
        return $IDs[0] ?? 0;
    }

    /**
     * Familie und Umrechnung einer Einheit. Unbekannte Einheiten bilden eine eigene Familie ohne Umrechnung.
     */
    private function describeUnit(string $Unit): array
    {
        $Key = mb_strtolower(trim($Unit));
        $Key = $Key === 'm3' ? 'm³' : $Key;
        if (isset(self::UNITS[$Key])) {
            [$Family, $Factor, $Kind] = self::UNITS[$Key];
            return [
                'Key'    => $Key,
                'Family' => $Family,
                'Factor' => $Factor,
                'Kind'   => $Kind,
                'Out'    => $Family === 'energy' ? 'kWh' : 'm³'
            ];
        }
        return ['Key' => $Key, 'Family' => 'custom:' . $Key, 'Factor' => 1.0, 'Kind' => 'counter', 'Out' => trim($Unit)];
    }

    /**
     * Währung und Unterwährung einer Preisvariable aus dem Suffix ihrer Darstellung, zum Beispiel "ct/kWh" oder "CHF/kWh".
     * Currency ist der Code, null wenn nicht eindeutig. Sub ist true bei Cent, Pence, Øre und ähnlichem, null wenn nicht erkennbar.
     */
    private function detectPriceUnit(int $PriceID): array
    {
        $Suffix = $PriceID > 0 && IPS_VariableExists($PriceID) ? $this->getUnit($PriceID) : '';
        $Token = trim(explode('/', mb_strtolower($Suffix))[0]);
        $Token = trim(rtrim($Token, '.'));
        if ($Token !== '' && isset(self::PRICE_UNITS[$Token])) {
            return ['Currency' => self::PRICE_UNITS[$Token][0], 'Sub' => self::PRICE_UNITS[$Token][1], 'Suffix' => $Suffix];
        }
        // Wie bisher: enthält die Einheit ct oder cent, gilt der Preis in Cent
        if (str_contains(mb_strtolower($Suffix), 'cent') || str_contains(mb_strtolower($Suffix), 'ct')) {
            return ['Currency' => 'EUR', 'Sub' => true, 'Suffix' => $Suffix];
        }
        return ['Currency' => null, 'Sub' => null, 'Suffix' => $Suffix];
    }

    /**
     * Faktor zur Hauptwährung für eine Preisvariable: 0,01 bei Preis in Cent oder Ähnlichem, sonst 1.
     * Der Modus gilt wie in den Verbrauchsquellen: 0 automatisch (aus dem Suffix), 1 Hauptwährung, 2 Unterwährung.
     */
    private function priceFactor(int $PriceID, int $Mode = 0): float
    {
        if ($Mode === 1) {
            return 1.0;
        }
        if ($Mode === 2) {
            return 0.01;
        }
        return $this->detectPriceUnit($PriceID)['Sub'] === true ? 0.01 : 1.0;
    }

    /**
     * Anzeigetext der Währung für Suffix und Kacheln: das Symbol der Auswahl, sonst der Code selbst (auch eigenes Symbol).
     */
    private function currencySymbol(string $Code): string
    {
        return self::CURRENCIES[$Code] ?? $Code;
    }

    /**
     * Einheit einer Variable: zuerst Suffix der Darstellung (Symcon 8 und höher), dann Suffix des Profils.
     */
    private function getUnit(int $VariableID): string
    {
        $Variable = IPS_GetVariable($VariableID);
        foreach ([$Variable['VariableCustomPresentation'] ?? [], $Variable['VariablePresentation'] ?? []] as $Presentation) {
            if (is_array($Presentation) && trim((string) ($Presentation['SUFFIX'] ?? '')) !== '') {
                return trim((string) $Presentation['SUFFIX']);
            }
        }
        $Profile = ($Variable['VariableCustomProfile'] ?? '') !== '' ? $Variable['VariableCustomProfile'] : ($Variable['VariableProfile'] ?? '');
        if ($Profile !== '' && IPS_VariableProfileExists($Profile)) {
            return trim((string) IPS_GetVariableProfile($Profile)['Suffix']);
        }
        return '';
    }

    /**
     * Einheitsangaben einer Variable. Hat sie keine Einheit, gilt die Ersatzangabe.
     */
    private function unitInfoFor(int $VariableID, array $Fallback): array
    {
        $Own = $this->getUnit($VariableID);
        return $Own === '' ? $Fallback : $this->describeUnit($Own);
    }

    /**
     * Verbrauch einer archivierten Variable im Bereich in der Basiseinheit. Der Aufrufer prüft vorher Archivierung und Einheit.
     * Ebene 0 = stündlich, 1 = täglich. Es zählen nur Datensätze, die im Bereich beginnen.
     */
    private function consumptionBetween(int $ArchiveID, int $ID, array $Info, int $Start, int $End, int $Level): float
    {
        $Records = AC_GetAggregatedValues($ArchiveID, $ID, $Info['Kind'] === 'power' ? 0 : $Level, $Start, $End, 0);
        $Records = array_filter($Records, fn (array $Record): bool => $Record['TimeStamp'] >= $Start && $Record['TimeStamp'] < $End);
        if ($Records === []) {
            return 0.0;
        }

        if ($Info['Kind'] === 'power') {
            // Leistung über die Zeit integrieren: Mittelwert mal Dauer, Ergebnis in kWh
            $Sum = 0.0;
            foreach ($Records as $Record) {
                $Sum += (float) $Record['Avg'] * (int) $Record['Duration'] / 3600;
            }
            return $Sum * $Info['Factor'];
        }

        if (AC_GetAggregationType($ArchiveID, $ID) === 1) {
            // Zähler-Aggregation: Avg ist die Summe der positiven Differenzen, ein Zähler-Reset verfälscht nichts
            return array_sum(array_column($Records, 'Avg')) * $Info['Factor'];
        }

        // Standard-Aggregation eines Zählerstands: Spanne zwischen kleinstem und größtem Wert. Ein Reset im Zeitraum verfälscht das Ergebnis.
        $this->SendDebug(__FUNCTION__, 'Variable ' . $ID . ' ist nicht als Zähler aggregiert, Verbrauch aus Max minus Min', 0);
        return max(0.0, (max(array_column($Records, 'Max')) - min(array_column($Records, 'Min'))) * $Info['Factor']);
    }

    /**
     * Verbrauch je Stunde in der Basiseinheit, Schlüssel ist der Beginn der Stunde.
     * Nur für Leistungswerte und Zähler mit Zähler-Aggregation, bei Standard-Aggregation sind Stundendifferenzen nicht additiv (dann null).
     */
    private function hourlySeries(int $ArchiveID, int $ID, array $Info, int $Start, int $End): ?array
    {
        if ($Info['Kind'] !== 'power' && AC_GetAggregationType($ArchiveID, $ID) !== 1) {
            $this->SendDebug(__FUNCTION__, 'Variable ' . $ID . ' hat keine Zähler-Aggregation, Stundenwerte nicht möglich', 0);
            return null;
        }

        $Hours = [];
        foreach (AC_GetAggregatedValues($ArchiveID, $ID, 0, $Start, $End, 0) as $Record) {
            if ($Record['TimeStamp'] < $Start || $Record['TimeStamp'] >= $End) {
                continue;
            }
            $Value = $Info['Kind'] === 'power' ? (float) $Record['Avg'] * (int) $Record['Duration'] / 3600 : (float) $Record['Avg'];
            $Hours[(int) $Record['TimeStamp']] = $Value * $Info['Factor'];
        }
        ksort($Hours);
        return $Hours;
    }

    /**
     * Verbrauch je Tag in der Basiseinheit, Schlüssel ist der Beginn des Tages. Tage ohne Datensatz fehlen in der Liste.
     * Leistung wird aus den Stundenwerten summiert, Zähler mit Zähler-Aggregation liefern den Tageswert direkt, bei einem
     * Zählerstand mit Standard-Aggregation gilt die Spanne zwischen kleinstem und größtem Wert des Tages.
     *
     * @return array<int, float>
     */
    private function dailySeries(int $ArchiveID, int $ID, array $Info, int $Start, int $End): array
    {
        $Days = [];
        $Power = $Info['Kind'] === 'power';
        $Counter = !$Power && AC_GetAggregationType($ArchiveID, $ID) === 1;
        foreach (AC_GetAggregatedValues($ArchiveID, $ID, $Power ? 0 : 1, $Start, $End, 0) as $Record) {
            if ($Record['TimeStamp'] < $Start || $Record['TimeStamp'] >= $End) {
                continue;
            }
            if ($Power) {
                $Value = (float) $Record['Avg'] * (int) $Record['Duration'] / 3600;
            } elseif ($Counter) {
                $Value = (float) $Record['Avg'];
            } else {
                $Value = max(0.0, (float) $Record['Max'] - (float) $Record['Min']);
            }
            $Day = (int) strtotime(date('Y-m-d', (int) $Record['TimeStamp']));
            $Days[$Day] = ($Days[$Day] ?? 0.0) + $Value * $Info['Factor'];
        }
        ksort($Days);
        return $Days;
    }

    /**
     * Heizgradtage (Kelvin mal Tag) nach VDI 3807: Für jeden Tag, dessen Mitteltemperatur unter der Heizgrenze liegt,
     * zählt die Differenz zur Raumtemperatur. Wärmere Tage zählen nicht, Tage ohne Temperaturwert bleiben außen vor.
     */
    private function degreeDaysBetween(int $ArchiveID, int $TemperatureID, int $Start, int $End, float $Room, float $Limit): float
    {
        if ($End <= $Start) {
            return 0.0;
        }
        $Sum = 0.0;
        foreach (AC_GetAggregatedValues($ArchiveID, $TemperatureID, 1, $Start, $End, 0) as $Record) {
            if ($Record['TimeStamp'] < $Start || $Record['TimeStamp'] >= $End) {
                continue;
            }
            $Mean = (float) $Record['Avg'];
            if ($Mean < $Limit) {
                $Sum += $Room - $Mean;
            }
        }
        return $Sum;
    }

    /**
     * Kosten mit variablem Preis: Verbrauch der Quelle je Stunde mal Preis der Stunde, aufsummiert. Preise in Cent oder
     * einer anderen Unterwährung werden durch 100 geteilt (Modus siehe priceFactor). Fehlt für eine Stunde ein Preis, gilt der zuletzt bekannte, vor dem ersten Eintrag der erste.
     * Null, wenn es nicht geht (Preisvariable oder Quelle nicht archiviert, Zähler ohne Zähler-Aggregation).
     */
    private function hourlyPriceCost(int $ArchiveID, int $PriceID, int $SourceID, array $SourceInfo, int $Start, int $End, int $PriceFactorMode = 0): ?float
    {
        if ($PriceID <= 0 || $SourceID <= 0 || !AC_GetLoggingStatus($ArchiveID, $PriceID) || !AC_GetLoggingStatus($ArchiveID, $SourceID)) {
            return null;
        }
        $Consumption = $this->hourlySeries($ArchiveID, $SourceID, $SourceInfo, $Start, $End);
        if ($Consumption === null) {
            return null;
        }

        $Factor = $this->priceFactor($PriceID, $PriceFactorMode);
        $Prices = [];
        foreach (AC_GetAggregatedValues($ArchiveID, $PriceID, 0, $Start, $End, 0) as $Record) {
            $Prices[(int) $Record['TimeStamp']] = (float) $Record['Avg'] * $Factor;
        }
        if ($Prices === []) {
            return null;
        }
        ksort($Prices);

        $Cost = 0.0;
        $Last = reset($Prices);
        foreach ($Consumption as $Hour => $Value) {
            $Last = $Prices[$Hour] ?? $Last;
            $Cost += $Value * $Last;
        }
        return $Cost;
    }
}