<?php

declare(strict_types=1);

/**
 * Emissionsfaktoren und Heizwerte, gebraucht von CO2Bilanz und Verbrauchsbericht. Typisiert für IPSModuleStrict.
 * Die Werte sind ungefähre Angaben für Deutschland (Quelle: Umweltbundesamt) in g CO2 je kWh.
 *
 * factorHours() und co2Of() brauchen die Traits ArchiveConsumption und ConsumptionTree im selben Modul (hourlySeries, unitInfoFor, consumption).
 */
trait EmissionFactors
{
    // Faktoren der Heizung nach Energieträger. Pellets und Sonstiges haben keinen eigenen Wert, Strom zählt beim Strom.
    private const HEATING_FACTORS = ['gas' => 201.0, 'oil' => 266.0, 'district' => 180.0];

    // Heizwert in kWh je Einheit für Erdgas (m³) und Heizöl (l), wenn der Heizungsblock keinen angibt
    private const DEFAULT_KWH_PER_UNIT = 10.0;

    /**
     * Faktor der Heizung in g je kWh: der eigene Wert aus dem Heizungsblock, sonst der des Energieträgers. 0, wenn es keinen gibt (Strom, Pellets, Sonstiges).
     */
    private function heatingFactor(array $Heating): float
    {
        if (($Heating['Carrier'] ?? '') === 'electric') {
            return 0.0;
        }
        $Own = (float) ($Heating['EmissionFactor'] ?? 0);
        return $Own > 0 ? $Own : (self::HEATING_FACTORS[(string) ($Heating['Carrier'] ?? '')] ?? 0.0);
    }

    /**
     * kWh je Einheit der Heizungsvariable: 1 bei Energie, sonst der Heizwert aus dem Block, bei Erdgas und Heizöl ersatzweise etwa 10. Null ohne Heizwert.
     */
    private function heatingEnergyPerUnit(array $Heating, array $UnitInfo): ?float
    {
        if ($UnitInfo['Family'] === 'energy') {
            return 1.0;
        }
        if ((float) ($Heating['EnergyPerUnit'] ?? 0) > 0) {
            return (float) $Heating['EnergyPerUnit'];
        }
        return in_array($Heating['Carrier'] ?? '', ['gas', 'oil'], true) ? self::DEFAULT_KWH_PER_UNIT : null;
    }

    /**
     * Faktoren der Stunden aus der Variable in g je kWh. Fehlt für eine Stunde ein Wert, gilt der zuletzt bekannte, vor dem ersten der erste.
     * Null ohne Variable oder Archivierung.
     *
     * @return array<int, float>|null
     */
    private function factorHours(array $Config, int $ArchiveID, int $Start, int $End): ?array
    {
        $ID = (int) $Config['EmissionID'];
        if ($ID <= 0 || !IPS_VariableExists($ID) || !AC_GetLoggingStatus($ArchiveID, $ID)) {
            return null;
        }
        $Hours = [];
        foreach (AC_GetAggregatedValues($ArchiveID, $ID, 0, $Start, $End, 0) as $Record) {
            $Hours[(int) $Record['TimeStamp']] = (float) $Record['Avg'];
        }
        ksort($Hours);
        return $Hours === [] ? null : $Hours;
    }

    /**
     * CO2 einer Quelle im Bereich in kg. Mit Faktor-Variable zählt jede Stunde mit ihrem Faktor, sonst gilt der feste Faktor.
     */
    private function co2Of(array $Config, int $ArchiveID, int $ID, int $Start, int $End, int $Level, array $FactorHours, float $Fixed): ?array
    {
        $Skipped = [];
        $Value = $this->consumption($Config, $ArchiveID, $ID, $Start, $End, $Level, $Skipped);
        if ($Value === null) {
            return null;
        }
        if ($FactorHours === [] || $Config['Unit']['Family'] !== 'energy') {
            return ['kg' => $Value * $Fixed / 1000, 'consumed' => $Value];
        }
        $Info = $this->unitInfoFor($ID, $Config['Unit']);
        $Series = $this->hourlySeries($ArchiveID, $ID, $Info, $Start, $End);
        if ($Series === null) {
            return ['kg' => $Value * $Fixed / 1000, 'consumed' => $Value];
        }
        $Times = array_keys($FactorHours);
        $Sum = 0.0;
        foreach ($Series as $Time => $Hour) {
            // Faktor der Stunde, sonst der zuletzt bekannte davor, vor dem ersten der erste
            $Factor = $FactorHours[$Time] ?? null;
            if ($Factor === null) {
                $Last = null;
                foreach ($Times as $Known) {
                    if ($Known <= $Time) {
                        $Last = $Known;
                    } else {
                        break;
                    }
                }
                $Factor = $FactorHours[$Last ?? $Times[0]];
            }
            $Sum += $Hour * $Factor;
        }
        return ['kg' => $Sum / 1000, 'consumed' => $Value];
    }
}
