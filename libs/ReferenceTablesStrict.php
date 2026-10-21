<?php

declare(strict_types=1);

/**
 * Referenztabellen als Baum im Formular: je Jahr eine Gruppe, darunter die Zeilen des Jahres. Gebraucht von Stromspiegel und Heizspiegel.
 * Die Zeilen stehen in der Eigenschaft 'Tables' als JSON-Liste mit id, parent und den Spalten, das Jahr einer Zeile ist das Jahr ihrer Gruppe.
 * Eine Liste im früheren flachen Format (ohne id, mit Jahr je Zeile) wird beim Lesen umgewandelt. Typisiert für IPSModuleStrict.
 *
 * Das Modul stellt bereit: referenceDefaultYear(): int, referenceDefaultRows(int $Year): array (flache Zeilen) und
 * referenceGroupRow(): array (die Spalten einer Gruppenzeile mit Nullwerten). Im Formular heißt der Baum 'Tables', die Eingabe
 * für das neue Jahr 'NewYear'. Die Schaltflächen rufen CopyYear und AddDefaults des Moduls auf.
 */
trait ReferenceTables
{
    /**
     * Das Formular mit dem Baum der Referenztabellen: Die Werte kommen immer aus der gespeicherten Liste, auch im früheren Format.
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(dirname((string) (new ReflectionClass($this))->getFileName()) . '/form.json'), true);
        $Fill = function (array &$Items) use (&$Fill): void
        {
            foreach ($Items as &$Item) {
                if (($Item['name'] ?? '') === 'Tables') {
                    $Item['values'] = $this->referenceTreeRows();
                    $Item['loadValuesFromConfiguration'] = false;
                }
                if (isset($Item['items']) && is_array($Item['items'])) {
                    $Fill($Item['items']);
                }
            }
        };
        $Fill($Form['elements']);
        // Das Modul kann das Formular ergänzen, zum Beispiel Auswahllisten mit Werten aus den Verbrauchsquellen
        if (method_exists($this, 'extendConfigurationForm')) {
            $this->extendConfigurationForm($Form);
        }
        return (string) json_encode($Form);
    }

    /**
     * Legt im Formular eine Tabelle für ein neues Jahr an, als Kopie der Tabelle des letzten Jahres davor. Die Änderung gilt erst nach
     * "Übernehmen". Gibt es das Jahr schon, ändert sich nichts.
     */
    public function CopyYear(int $Year): void
    {
        $Tree = $this->referenceTreeRows();
        $Years = [];
        foreach ($Tree as $Row) {
            if ((int) ($Row['parent'] ?? 0) === 0) {
                $Years[(int) $Row['id']] = (int) $Row['Year'];
            }
        }
        if ($Year >= 2000 && $Year <= 2100 && !in_array($Year, $Years, true)) {
            $Earlier = array_filter($Years, fn (int $Y): bool => $Y < $Year);
            $SourceYear = $Earlier === [] ? ($Years === [] ? 0 : max($Years)) : max($Earlier);
            $Children = [];
            foreach ($Tree as $Row) {
                if ($SourceYear > 0 && ($Years[(int) ($Row['parent'] ?? 0)] ?? null) === $SourceYear) {
                    $Children[] = $Row;
                }
            }
            $Tree = $this->referenceAppendYear($Tree, $Year, $SourceYear > 0 ? $Children : $this->referenceDefaultRows($Year));
        }
        $this->UpdateFormField('Tables', 'values', (string) json_encode($Tree));
    }

    /**
     * Fügt im Formular die Vorgabe hinzu, falls ihr Jahr noch fehlt. Gilt erst nach "Übernehmen".
     */
    public function AddDefaults(): void
    {
        $Tree = $this->referenceTreeRows();
        foreach ($Tree as $Row) {
            if ((int) ($Row['parent'] ?? 0) === 0 && (int) $Row['Year'] === $this->referenceDefaultYear()) {
                $this->UpdateFormField('Tables', 'values', (string) json_encode($Tree));
                return;
            }
        }
        $Year = $this->referenceDefaultYear();
        $this->UpdateFormField('Tables', 'values', (string) json_encode($this->referenceAppendYear($Tree, $Year, $this->referenceDefaultRows($Year))));
    }

    /**
     * Die gespeicherte Liste als flache Zeilen mit Jahr. Im Baum-Format gilt das Jahr der Gruppe, zu der eine Zeile gehört,
     * die Gruppenzeilen selbst fallen weg.
     *
     * @return array<int, array<string, mixed>>
     */
    private function referenceFlatRows(): array
    {
        $Rows = json_decode($this->ReadPropertyString('Tables'), true);
        if (!is_array($Rows)) {
            return [];
        }
        $IsTree = false;
        foreach ($Rows as $Row) {
            if (is_array($Row) && array_key_exists('id', $Row)) {
                $IsTree = true;
                break;
            }
        }
        if (!$IsTree) {
            return array_values(array_filter($Rows, 'is_array'));
        }
        $Years = [];
        foreach ($Rows as $Row) {
            if (is_array($Row) && (int) ($Row['parent'] ?? 0) === 0) {
                $Years[(int) $Row['id']] = (int) ($Row['Year'] ?? 0);
            }
        }
        $Flat = [];
        foreach ($Rows as $Row) {
            $Parent = is_array($Row) ? (int) ($Row['parent'] ?? 0) : 0;
            if ($Parent !== 0 && isset($Years[$Parent])) {
                $Row['Year'] = $Years[$Parent];
                $Flat[] = $Row;
            }
        }
        return $Flat;
    }

    /**
     * Die Zeilen für den Baum im Formular. Eine Liste im früheren flachen Format wird umgewandelt.
     *
     * @return array<int, array<string, mixed>>
     */
    private function referenceTreeRows(): array
    {
        $Rows = json_decode($this->ReadPropertyString('Tables'), true);
        if (!is_array($Rows) || $Rows === []) {
            return [];
        }
        foreach ($Rows as $Row) {
            if (is_array($Row) && array_key_exists('id', $Row)) {
                return array_values($Rows);
            }
        }
        return $this->referenceTreeFromFlat($this->referenceFlatRows());
    }

    /**
     * Wandelt flache Zeilen in einen Baum: je Jahr eine Gruppenzeile, das neueste Jahr aufgeklappt, darunter die Zeilen des Jahres.
     *
     * @param array<int, array<string, mixed>> $Flat
     * @return array<int, array<string, mixed>>
     */
    private function referenceTreeFromFlat(array $Flat): array
    {
        $ByYear = [];
        foreach ($Flat as $Row) {
            $ByYear[(int) ($Row['Year'] ?? 0)][] = $Row;
        }
        krsort($ByYear);
        $Tree = [];
        $Id = 1;
        $First = true;
        foreach ($ByYear as $Year => $Rows) {
            $Parent = $Id++;
            $Tree[] = ['id' => $Parent, 'Year' => $Year] + $this->referenceGroupRow() + ['expanded' => $First];
            $First = false;
            foreach ($Rows as $Row) {
                $Row['Year'] = $Year;
                $Tree[] = ['id' => $Id++, 'parent' => $Parent] + $Row;
            }
        }
        return $Tree;
    }

    /**
     * Hängt eine Gruppe für ein Jahr mit den gegebenen Zeilen an den Baum, mit neuen Nummern.
     *
     * @param array<int, array<string, mixed>> $Tree
     * @param array<int, array<string, mixed>> $Rows
     * @return array<int, array<string, mixed>>
     */
    private function referenceAppendYear(array $Tree, int $Year, array $Rows): array
    {
        $Id = 1 + max(array_merge([0], array_map(fn (array $Row): int => (int) $Row['id'], $Tree)));
        $Parent = $Id++;
        $Tree[] = ['id' => $Parent, 'Year' => $Year] + $this->referenceGroupRow() + ['expanded' => true];
        foreach ($Rows as $Row) {
            unset($Row['id'], $Row['parent'], $Row['expanded']);
            $Row['Year'] = $Year;
            $Tree[] = ['id' => $Id++, 'parent' => $Parent] + $Row;
        }
        return $Tree;
    }
}
