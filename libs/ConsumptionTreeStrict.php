<?php

declare(strict_types=1);

/**
 * Verbraucher und Gruppen der Verbrauchsquellen: Konfiguration vom Splitter lesen, Werte je Variable aus dem Archiv holen und
 * zu einer Hierarchie aus Gruppen und Geräten aufbauen. Gebraucht von Top Verbraucher, Verbrauchsbericht, Verbrauchsfluss, Lastgang und Lastspitzen, beide nutzen auch die Traits
 * ArchiveConsumption und SourceChild. Typisiert für IPSModuleStrict.
 */
trait ConsumptionTree
{
    /**
     * Konfiguration vom Splitter Verbrauchsquellen, mit Gruppen nach Namen und Verbrauchern nach Variable geordnet.
     * Ohne aktiven Splitter ist "NoSources" gesetzt und die Listen sind leer.
     */
    private function readConfiguration(): array
    {
        $Sources = $this->requestSources();
        if ($Sources === null) {
            return [
                'NoSources'  => true,
                'Unit'       => $this->describeUnit('kWh'),
                'Groups'     => [],
                'Consumers'  => [],
                'MainMeter'  => 0,
                'PriceID'    => 0,
                'FixedPrice' => 0.0,
                'Residents'  => 0,
                'Area'       => 0.0
            ];
        }

        $Groups = [];
        foreach ($Sources['Groups'] ?? [] as $Group) {
            $Groups[$Group['Name']] = $Group;
        }
        $Consumers = [];
        foreach ($Sources['Consumers'] ?? [] as $Consumer) {
            $Consumers[(int) $Consumer['ID']] = $Consumer;
        }

        return [
            'NoSources'        => false,
            'Unit'             => $Sources['Unit'],
            'Groups'           => $Groups,
            'Consumers'        => $Consumers,
            'MainMeter'        => (int) ($Sources['MainMeter'] ?? 0),
            'PriceID'          => (int) ($Sources['PriceID'] ?? 0),
            'FixedPrice'       => (float) ($Sources['FixedPrice'] ?? 0.0),
            'EmissionFactor'   => (float) ($Sources['EmissionFactor'] ?? 0.0),
            'EmissionID'       => (int) ($Sources['EmissionID'] ?? 0),
            'Residents'        => (int) ($Sources['Residents'] ?? 0),
            'Area'             => (float) ($Sources['Area'] ?? 0.0),
            'HotWaterElectric' => (bool) ($Sources['HotWaterElectric'] ?? false)
        ];
    }
    // ---------------------------------------------------------------- Formular

    // ---------------------------------------------------------------- Berechnung

    /**
     * Verbrauch aller Variablen im Bereich. Wert null = nicht auswertbar (kein Archiv, falsche Einheit).
     */
    private function evaluate(array $Config, int $ArchiveID, int $Start, int $End, int $Level, array &$Skipped): array
    {
        $IDs = array_keys($Config['Consumers']);
        if ($Config['MainMeter'] > 0) {
            $IDs[] = $Config['MainMeter'];
        }
        foreach ($Config['Groups'] as $Group) {
            if ($Group['MeterID'] > 0) {
                $IDs[] = $Group['MeterID'];
            }
        }

        $Values = [];
        foreach (array_unique($IDs) as $ID) {
            $Values[$ID] = $this->consumption($Config, $ArchiveID, $ID, $Start, $End, $Level, $Skipped);
        }
        return $Values;
    }

    private function consumption(array $Config, int $ArchiveID, int $ID, int $Start, int $End, int $Level, array &$Skipped): ?float
    {
        $Info = $this->unitInfoFor($ID, $Config['Unit']);
        if ($Info['Family'] !== $Config['Unit']['Family']) {
            $Skipped[$ID] = 'unit';
            return null;
        }
        if (!AC_GetLoggingStatus($ArchiveID, $ID)) {
            $Skipped[$ID] = 'archive';
            return null;
        }
        return $this->consumptionBetween($ArchiveID, $ID, $Info, $Start, $End, $Level);
    }

    /**
     * Baut die Hierarchie unterhalb einer Gruppe. Eine Gruppe mit Zähler zeigt den Zählerwert und führt die Differenz zu den Mitgliedern als "Rest der Gruppe".
     */
    private function buildTree(string $Parent, array $Config, array $Values): array
    {
        $Nodes = [];
        foreach ($Config['Groups'] as $Group) {
            if ($Group['Parent'] !== $Parent) {
                continue;
            }
            $Children = $this->buildTree($Group['Name'], $Config, $Values);
            $Sum = array_sum(array_column($Children, 'value'));
            $Meter = $Group['MeterID'] > 0 ? ($Values[$Group['MeterID']] ?? null) : null;
            $Value = $Sum;
            if ($Meter !== null) {
                $Value = $Meter;
                if ($Meter - $Sum > 0.0005) {
                    $Children[] = ['id' => 'rest:' . $Group['Name'], 'type' => 'rest', 'name' => $this->Translate('Rest of group'), 'value' => $Meter - $Sum, 'children' => []];
                }
            }
            if ($Children === [] && $Meter === null) {
                continue;
            }
            $Nodes[] = [
                'id'       => 'g:' . $Group['Name'],
                'type'     => 'group',
                'name'     => $Group['Name'],
                'icon'     => $Group['Icon'],
                'objectId' => (int) $Group['MeterID'],
                'color'    => $Group['Color'] >= 0 ? sprintf('#%06X', $Group['Color']) : '',
                'value'    => $Value,
                'children' => $Children
            ];
        }
        foreach ($Config['Consumers'] as $Consumer) {
            $Value = $Values[$Consumer['ID']] ?? null;
            if ($Consumer['Group'] === $Parent && $Value !== null) {
                $Nodes[] = ['id' => 'v:' . $Consumer['ID'], 'type' => 'var', 'name' => $Consumer['Name'], 'objectId' => (int) $Consumer['ID'], 'value' => $Value, 'children' => []];
            }
        }
        // Der Rest einer Gruppe steht immer am Ende der Mitglieder, alles andere nach Wert absteigend
        usort($Nodes, fn (array $A, array $B): int => $B['value'] <=> $A['value']);
        usort($Nodes, fn (array $A, array $B): int => ($A['type'] === 'rest') <=> ($B['type'] === 'rest'));
        return $Nodes;
    }

    private function flatten(array $Nodes, array &$Map): void
    {
        foreach ($Nodes as $Node) {
            $Map[$Node['id']] = $Node['value'];
            $this->flatten($Node['children'], $Map);
        }
    }

    /**
     * Variablen, aus denen sich eine Quelle zusammensetzt: der Hauptzähler oder alle Verbraucher beim Gesamtverbrauch,
     * der Zähler einer Gruppe oder ihre Mitglieder samt Untergruppen, sonst die einzelne Variable.
     *
     * @return array<int, int>
     */
    private function memberIDs(array $Config, string $Scope): array
    {
        if ($Scope === 'total') {
            return $Config['MainMeter'] > 0 ? [$Config['MainMeter']] : array_map('intval', array_keys($Config['Consumers']));
        }
        if (str_starts_with($Scope, 'v:')) {
            $ID = (int) substr($Scope, 2);
            return isset($Config['Consumers'][$ID]) ? [$ID] : [];
        }
        if (str_starts_with($Scope, 'g:')) {
            $Name = substr($Scope, 2);
            if (!isset($Config['Groups'][$Name])) {
                return [];
            }
            if ($Config['Groups'][$Name]['MeterID'] > 0) {
                return [(int) $Config['Groups'][$Name]['MeterID']];
            }
            $IDs = [];
            foreach ($Config['Consumers'] as $ID => $Consumer) {
                if ($Consumer['Group'] === $Name) {
                    $IDs[] = (int) $ID;
                }
            }
            foreach ($Config['Groups'] as $Group) {
                if ($Group['Parent'] === $Name) {
                    $IDs = array_merge($IDs, $this->memberIDs($Config, 'g:' . $Group['Name']));
                }
            }
            return array_values(array_unique($IDs));
        }
        return [];
    }

    /**
     * Auswahlliste der Quellen: Gesamtverbrauch, Gruppen und Geräte als Baum mit Einrückung.
     *
     * @return array<int, array{id: string, name: string, level: int}>
     */
    private function scopes(array $Config, string $TotalName): array
    {
        $Probe = [];
        foreach ($Config['Consumers'] as $ID => $Consumer) {
            $Probe[$ID] = 1.0;
        }
        foreach ($Config['Groups'] as $Group) {
            if ($Group['MeterID'] > 0) {
                $Probe[$Group['MeterID']] = 1.0;
            }
        }
        $Scopes = [['id' => 'total', 'name' => $TotalName, 'level' => 0]];
        $Walk = function (array $Nodes, int $Level) use (&$Walk, &$Scopes): void
        {
            foreach ($Nodes as $Node) {
                if ($Node['type'] === 'rest') {
                    continue;
                }
                $Scopes[] = ['id' => $Node['id'], 'name' => $Node['name'], 'level' => $Level];
                $Walk($Node['children'], $Level + 1);
            }
        };
        $Walk($this->buildTree('', $Config, $Probe), 1);
        return $Scopes;
    }
}
