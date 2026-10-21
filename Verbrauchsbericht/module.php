<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';
require_once __DIR__ . '/../libs/CustomPeriodStrict.php';
require_once __DIR__ . '/../libs/ConsumptionReportPdf.php';
require_once __DIR__ . '/../libs/EmissionFactorsStrict.php';

/**
 * Verbrauchsbericht: Eine Liste von Berichten, jeder mit eigenem Medium, Zeitraum (Tag, Woche, Monat oder Jahr), Inhalt, Ausgabe (PDF, CSV)
 * und Zeitplan. Der Bericht entsteht zum eingestellten Zeitpunkt oder auf Knopfdruck in der Kachel, liegt als Text und Medienobjekt vor und geht
 * auf Wunsch per Mail über eine SMTP-Instanz von Symcon an einen Empfänger je Bericht.
 */
class Verbrauchsbericht extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;
    use CustomPeriod;
    use EmissionFactors;

    private const SMTP_MODULE_ID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';
    private const FILE_PREFIX = 'Verbrauchsbericht_';

    private const PERIOD_DAY = 0;
    private const PERIOD_WEEK = 1;
    private const PERIOD_MONTH = 2;
    private const PERIOD_YEAR = 3;
    private const PERIOD_CUSTOM = 4;

    private const LIST_NONE = 0;
    private const LIST_TREE = 1;
    private const LIST_TOP = 2;

    // Je Periode: Schlüssel im Dateinamen, Tage zum Durchschnitt davor, Schwelle für den Hinweis
    private const PERIODS = [
        self::PERIOD_DAY   => ['key' => 'day', 'lookback' => 14, 'threshold' => 0.25],
        self::PERIOD_WEEK  => ['key' => 'week', 'lookback' => 28, 'threshold' => 0.20],
        self::PERIOD_MONTH => ['key' => 'month', 'lookback' => 90, 'threshold' => 0.15],
        self::PERIOD_YEAR  => ['key' => 'year', 'lookback' => 365, 'threshold' => 0.10]
    ];

    // Der Timer läuft höchstens so lange am Stück, damit er Zeitumstellungen und Neustarts übersteht
    private const MAX_TICK_SECONDS = 21600;

    // Einstellungen zum Inhalt, die sich in der Kachel ändern lassen: Schlüssel der Kachel => [Spalte der Berichtsliste, Typ, Minimum, Maximum]
    private const OPTIONS = [
        'IncludeCost'        => ['Cost', 'bool'],
        'IncludePrevious'    => ['Previous', 'bool'],
        'IncludeLastYear'    => ['LastYear', 'bool'],
        'IncludeNotice'      => ['Notice', 'bool'],
        'IncludeIndicators'  => ['Indicators', 'bool'],
        'IncludeCo2'         => ['Co2', 'bool'],
        'IncludeNotRecorded' => ['NotRecorded', 'bool'],
        'ListMode'           => ['ListMode', 'int', 0, 2],
        'TopN'               => ['TopN', 'int', 1, 100]
    ];

    // Ein Bericht der Liste mit seinen Vorgaben. Zeitplan: Auto, Hour, Minute, Weekday (Wochenberichte), MonthDay (Monats- und Jahresberichte).
    private const DEFAULT_REPORT = [
        'Name'        => 'Report',
        'Medium'      => 1,
        'Period'      => self::PERIOD_DAY,
        'Auto'        => false,
        'Hour'        => 7,
        'Minute'      => 0,
        'Weekday'     => 1,
        'MonthDay'    => 1,
        'Mail'        => false,
        'Recipient'   => '',
        'Subject'     => '',
        'PDF'         => false,
        'CSV'         => true,
        'Cost'        => true,
        'Previous'    => true,
        'LastYear'    => false,
        'Notice'      => true,
        'Indicators'  => false,
        'Co2'         => true,
        'NotRecorded' => true,
        'ListMode'    => self::LIST_TREE,
        'TopN'        => 5
    ];

    // Eigener Zeitraum der Kachel, nur während eines Aufrufs gesetzt: ['start' => Beginn, 'end' => Ende (ausschließlich)]
    private ?array $custom = null;

    // Der Bericht der Liste, der gerade gebaut wird (Einstellungen siehe opt())
    private ?array $Profile = null;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Je Medium eine Liste, damit das Formular nur die Spalten zeigt, die das Medium nutzen kann: Reports (Strom), Reports2 (Wasser), Reports3 (Gas), Reports4 (Heizung)
        $this->RegisterPropertyString('Reports', (string) json_encode([self::DEFAULT_REPORT]));
        foreach ([2, 3, self::MEDIUM_HEATING] as $Medium) {
            $this->RegisterPropertyString('Reports' . $Medium, '[]');
        }
        $this->RegisterPropertyBoolean('DecimalComma', true);
        $this->RegisterPropertyInteger('SMTPInstanceID', 0);

        $this->RegisterAttributeString('NextRuns', '{}');
        $this->RegisterAttributeString('Selected', '');
        $this->RegisterAttributeString('LastRequest', '');
        $this->RegisterTimer('Schedule', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Run\', \'\');');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 steht für HTML der Wert 1 aus der Doku.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function GetConfigurationForm(): string
    {
        return $this->sourceConfigurationForm();
    }

    public function GetVisualizationTile(): string
    {
        $Html = file_get_contents(__DIR__ . '/module.html');
        if ($Html === false) {
            $this->SendDebug(__FUNCTION__, 'module.html konnte nicht gelesen werden', 0);
            return '';
        }
        // handleMessage steht erst im HTML, deshalb folgen die Startdaten dahinter
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage('')) . ');</script>';
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
        $this->maintainMedia();

        $Sources = $this->requestSources();
        if ($Sources === null) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Splitter Verbrauchsquellen verbunden', 0);
            $this->SetTimerInterval('Schedule', 0);
            $this->SetStatus(self::STATUS_NO_SOURCES);
            return;
        }

        $Error = $this->validate();
        if ($Error !== '') {
            $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            $this->SetTimerInterval('Schedule', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        // Nach jeder Änderung zählt der neue Zeitplan, ein verpasster Termin wird nicht nachgeholt
        $this->WriteAttributeString('NextRuns', (string) json_encode($this->scheduleAll()));
        $this->armTimer();

        // Der Text des gewählten Berichts steht danach sofort in der Variable. Medienobjekt und Mail gibt es nur zum eingestellten Zeitpunkt oder auf Knopfdruck.
        if (IPS_GetKernelRunlevel() == KR_READY && $this->selectedReport() !== null) {
            $this->custom = null;
            $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
            $Period = null;
            if (is_array($Last) && isset($Last['period'])) {
                $Period = (int) $Last['period'];
                if ($Period === self::PERIOD_CUSTOM) {
                    $this->custom = is_array($Last['custom'] ?? null) ? $Last['custom'] : null;
                    $Period = $this->custom === null ? null : $Period;
                } elseif (!isset(self::PERIODS[$Period])) {
                    $Period = null;
                }
            }
            $this->buildReport(false, $Period, $this->selectedReport());
            $this->custom = null;
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
        if ($Ident === 'SelectReport') {
            $Reports = $this->reports();
            if (isset($Reports[(string) $Value])) {
                $this->WriteAttributeString('Selected', (string) $Value);
                // Hat der Bericht noch keine Daten, entstehen sie jetzt für den zuletzt gewählten Zeitraum, ohne Medienobjekt und Mail
                if ($this->reportData((string) $Value) === null) {
                    $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
                    $Period = is_array($Last) && isset($Last['period']) && isset(self::PERIODS[(int) $Last['period']]) ? (int) $Last['period'] : null;
                    $this->custom = null;
                    $this->buildReport(false, $Period, $Reports[(string) $Value]);
                }
            }
            $this->UpdateVisualizationValue($this->tileMessage(''));
            return;
        }
        if ($Ident === 'Download') {
            $this->sendDownload((string) $Value);
            return;
        }
        if ($Ident === 'Option') {
            $this->setOption((string) $Value);
            return;
        }
        if ($Ident === 'Create' || $Ident === 'Mail') {
            $Period = null;
            $this->custom = null;
            if (is_string($Value) && str_starts_with($Value, '{')) {
                $this->custom = $this->parseCustom($Value);
                if ($this->custom === null) {
                    $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The period is invalid')));
                    return;
                }
                $Period = self::PERIOD_CUSTOM;
            } else {
                $Period = (int) $Value;
                $Period = isset(self::PERIODS[$Period]) ? $Period : null;
            }
            $Profile = $this->selectedReport();
            $Report = $Profile === null ? null : $this->buildReport(true, $Period, $Profile);
            if ($Report !== null) {
                $this->WriteAttributeString('LastRequest', (string) json_encode(['period' => $Period, 'custom' => $this->custom]));
            }
            $this->custom = null;
            if ($Report === null) {
                $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The report could not be created')));
                return;
            }
            if ($Ident === 'Mail') {
                $Sent = $this->sendMail($Report, $Profile);
                $this->UpdateVisualizationValue($this->tileMessage($Sent ? $this->Translate('The report was sent') : $this->Translate('The report could not be sent')));
            } else {
                $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The report was created')));
            }
            return;
        }
        if ($Ident === 'Run') {
            $this->SetTimerInterval('Schedule', 0);
            $Runs = json_decode($this->ReadAttributeString('NextRuns'), true);
            $Runs = is_array($Runs) ? $Runs : [];
            foreach ($this->reports() as $Name => $Profile) {
                if (!$Profile['Auto']) {
                    continue;
                }
                $Next = (int) ($Runs[$Name] ?? 0);
                if ($Next > 0 && time() >= $Next) {
                    $this->custom = null;
                    $Report = $this->buildReport(true, null, $Profile);
                    if ($Report !== null && $Profile['Mail']) {
                        $this->sendMail($Report, $Profile);
                    }
                    $Next = $this->nextRun($Profile);
                } elseif ($Next <= 0) {
                    $Next = $this->nextRun($Profile);
                }
                $Runs[$Name] = $Next;
            }
            $this->WriteAttributeString('NextRuns', (string) json_encode($Runs));
            $this->armTimer();
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Erstellt den Bericht mit dem Namen aus der Liste (ohne Namen der in der Kachel gewählte), schreibt Variablen und Medienobjekte
     * und gibt den Text zurück. Leer, wenn es den Bericht nicht gibt.
     */
    public function CreateReport(string $Name): string
    {
        $Profile = $this->namedReport($Name);
        $this->custom = null;
        $Report = $Profile === null ? null : $this->buildReport(true, null, $Profile);
        return $Report === null ? '' : $Report['text'];
    }

    /**
     * Erstellt den Bericht und schickt ihn per Mail an den Empfänger des Berichts (ohne Empfänger an die Standardadresse der SMTP-Instanz),
     * mit PDF oder CSV als Anhang.
     */
    public function SendReport(string $Name): bool
    {
        $Profile = $this->namedReport($Name);
        $this->custom = null;
        $Report = $Profile === null ? null : $this->buildReport(true, null, $Profile);
        return $Report !== null && $this->sendMail($Report, $Profile);
    }

    // ---------------------------------------------------------------- Berichtsliste

    /**
     * Die Berichte der Liste mit ihren Vorgaben, nach Name. Zeilen ohne Namen und doppelte Namen zählen nicht.
     *
     * @return array<string, array<string, mixed>>
     */
    private function reports(): array
    {
        $Result = [];
        foreach ($this->reportRows() as $Row) {
            if ($Row['Name'] !== '' && !isset($Result[$Row['Name']])) {
                $Result[$Row['Name']] = $Row;
            }
        }
        return $Result;
    }

    /**
     * Alle Zeilen der vier Listen (Strom, Wasser, Gas, Heizung) mit Vorgaben und dem Medium der Liste, in der Reihenfolge der Listen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(): array
    {
        $All = [];
        foreach ([1, 2, 3, self::MEDIUM_HEATING] as $Medium) {
            $Rows = json_decode($this->ReadPropertyString($this->reportProperty($Medium)), true);
            foreach (is_array($Rows) ? $Rows : [] as $Row) {
                if (is_array($Row)) {
                    $Row = array_merge(self::DEFAULT_REPORT, array_intersect_key($Row, self::DEFAULT_REPORT));
                    $Row['Medium'] = $Medium;
                    $Row['Name'] = trim((string) $Row['Name']);
                    $All[] = $Row;
                }
            }
        }
        return $All;
    }

    /**
     * Name der Eigenschaft mit der Berichtsliste eines Mediums.
     */
    private function reportProperty(int $Medium): string
    {
        return $Medium === 1 ? 'Reports' : 'Reports' . $Medium;
    }

    /**
     * Der Bericht mit dem Namen, ohne Namen der in der Kachel gewählte.
     */
    private function namedReport(string $Name): ?array
    {
        $Reports = $this->reports();
        return $Name === '' ? $this->selectedReport() : ($Reports[$Name] ?? null);
    }

    /**
     * Der in der Kachel gewählte Bericht, sonst der erste der Liste.
     */
    private function selectedReport(): ?array
    {
        $Reports = $this->reports();
        $Name = $this->ReadAttributeString('Selected');
        return $Reports[$Name] ?? ($Reports === [] ? null : reset($Reports));
    }

    /**
     * Einstellung des Berichts, der gerade gebaut wird.
     */
    private function opt(string $Key): mixed
    {
        return $this->Profile[$Key] ?? self::DEFAULT_REPORT[$Key];
    }

    /**
     * Kennung des Medienobjekts eines Berichts: PDF oder CSV. Sie hängt vom Namen ab, ein umbenannter Bericht bekommt neue Objekte.
     */
    private function mediaIdent(string $Name, string $Kind): string
    {
        return 'Media_' . substr(md5($Name), 0, 8) . '_' . $Kind;
    }

    /**
     * Die Daten der Berichte für die Kachel, nach Name des Berichts.
     *
     * @return array<string, array<string, mixed>>
     */
    private function allReportData(): array
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        return is_array($Data) ? array_filter($Data, 'is_array') : [];
    }

    private function reportData(string $Name): ?array
    {
        return $this->allReportData()[$Name] ?? null;
    }

    // ---------------------------------------------------------------- Bericht

    /**
     * Baut den Bericht der Liste für die letzte volle Periode (oder die gewählte, auch einen eigenen Zeitraum). Mit $Write entstehen
     * die Medienobjekte. Das Medium des Berichts gilt für alle Abfragen der Verbrauchsquellen währenddessen.
     */
    private function buildReport(bool $Write, ?int $PeriodOverride, array $Profile): ?array
    {
        $this->Profile = $Profile;
        $this->MediumOverride = (int) $Profile['Medium'];
        try {
            return $this->buildReportFor($Write, $PeriodOverride);
        } finally {
            $this->Profile = null;
            $this->MediumOverride = null;
        }
    }

    private function buildReportFor(bool $Write, ?int $PeriodOverride): ?array
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return null;
        }
        $Config = $this->readConfiguration();
        if ($Config['NoSources'] || ($Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return null;
        }
        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return null;
        }

        $Period = $PeriodOverride ?? (int) $this->opt('Period');
        $Range = $this->periodRange($Period);
        $PeriodDays = max(1, (int) round(($Range['end'] - $Range['start']) / 86400));
        $Skipped = [];

        $Current = $this->evaluateTotal($Config, $ArchiveID, $Range['start'], $Range['end'], $Skipped);
        $Nodes = $Current['nodes'];
        $Total = $Current['total'];

        $Previous = $this->evaluateTotal($Config, $ArchiveID, $Range['prevStart'], $Range['start'], $Skipped);
        $UseLastYear = $Period !== self::PERIOD_YEAR && $this->opt('LastYear');
        $LastYear = $UseLastYear ? $this->evaluateTotal($Config, $ArchiveID, $Range['yearStart'], $Range['yearEnd'], $Skipped) : null;
        $Lookback = $this->periodInfo($Period)['lookback'];
        $LookStart = (new DateTimeImmutable('@' . $Range['start']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify('-' . $Lookback . ' days')->getTimestamp();
        $Average = $this->evaluateTotal($Config, $ArchiveID, $LookStart, $Range['start'], $Skipped);
        $AverageTotal = $Average['total'] / $Lookback * $PeriodDays;

        // Kosten: je Stunde mit der Preisvariable, sonst mit dem festen Preis
        $Cost = null;
        if ($Config['PriceID'] > 0 && $Config['MainMeter'] > 0) {
            $Info = $this->unitInfoFor($Config['MainMeter'], $Config['Unit']);
            if ($Info['Family'] === $Config['Unit']['Family']) {
                $Cost = $this->hourlyPriceCost($ArchiveID, $Config['PriceID'], $Config['MainMeter'], $Info, $Range['start'], $Range['end']);
            }
        }
        $Cost ??= $Total * (float) $Config['FixedPrice'];
        $PricePerUnit = $Total > 0 ? $Cost / $Total : 0.0;

        $Unit = $Config['Unit']['Out'];
        // Emissionsfaktor des Mediums aus den Verbrauchsquellen, 0 = kein CO2 (zum Beispiel bei Wasser)
        $Co2 = $this->opt('Co2') ? $this->reportCo2($Config, $ArchiveID, $Range, $Total) : null;
        // Mittlerer Faktor des Zeitraums in g je Einheit, auch bei stündlichem Faktor, für die CO2-Spalte der Zeilen
        $Co2Factor = ($Co2 !== null && $Total > 0) ? $Co2 / $Total * 1000 : 0.0;
        $PrevMap = [];
        $this->flatten($Previous['nodes'], $PrevMap);

        $ListMode = (int) $this->opt('ListMode');
        $AllRows = [];
        $this->collectRows($Nodes, 0, $Total, $PricePerUnit, $PrevMap, $AllRows);
        $Rows = $ListMode !== self::LIST_NONE ? $AllRows : [];
        if ($ListMode === self::LIST_TOP) {
            $Rows = array_values(array_filter($Rows, fn (array $Row): bool => $Row['type'] === 'var'));
            usort($Rows, fn (array $A, array $B): int => $B['value'] <=> $A['value']);
            $Rows = array_slice($Rows, 0, max(1, (int) $this->opt('TopN')));
            foreach ($Rows as $Index => $Row) {
                $Rows[$Index]['level'] = 0;
            }
        }
        $Listed = array_sum(array_column($Nodes, 'value'));
        // Bei der Heizung ist der Hauptzähler der einzige Posten, ein Rest dazu wäre irreführend
        if ($this->opt('NotRecorded') && (int) $this->opt('Medium') !== self::MEDIUM_HEATING && $Config['MainMeter'] > 0 && $Total > $Listed) {
            $Rest = $Total - $Listed;
            $Rows[] = ['level' => 0, 'type' => 'rest', 'name' => $this->Translate('Not recorded'), 'value' => $Rest, 'share' => $Total > 0 ? $Rest / $Total * 100 : 0.0, 'cost' => $Rest * $PricePerUnit, 'previous' => null];
        }

        $ChangePrevious = $Previous['total'] > 0 ? ($Total - $Previous['total']) / $Previous['total'] * 100 : 0.0;
        $ChangeLastYear = ($LastYear !== null && $LastYear['total'] > 0) ? ($Total - $LastYear['total']) / $LastYear['total'] * 100 : 0.0;
        $ChangeAverage = $AverageTotal > 0 ? ($Total - $AverageTotal) / $AverageTotal * 100 : 0.0;
        $Notice = '';
        if ($AverageTotal > 0 && abs($ChangeAverage) >= $this->periodInfo($Period)['threshold'] * 100) {
            $Notice = sprintf(
                $ChangeAverage > 0 ? $this->Translate('Unusually high: %s %% above the average of the last %d days') : $this->Translate('Unusually low: %s %% below the average of the last %d days'),
                number_format(abs($ChangeAverage), 0, '.', ''),
                $Lookback
            );
        }

        $Label = $this->periodLabel($Period, $Range['start'], $Range['end']);
        $Comma = $this->ReadPropertyBoolean('DecimalComma');
        $Num = fn (float $Value, int $Digits): string => number_format($Value, $Digits, $Comma ? ',' : '.', '');
        $Signed = fn (float $Value): string => ($Value > 0 ? '+' : '') . $Num($Value, 0);
        $WithCost = (bool) $this->opt('Cost');

        $Lines = [sprintf($this->Translate('Consumption report %s'), $this->opt('Name') . ' ' . $Label), ''];
        $TotalLine = sprintf($this->Translate('Total: %s %s'), $Num($Total, 2), $Unit);
        if ($WithCost) {
            $TotalLine .= sprintf($this->Translate(', cost %s EUR'), $Num($Cost, 2));
        }
        $Lines[] = $TotalLine;
        if ($Co2 !== null) {
            $Lines[] = sprintf($this->Translate('CO2: %s kg'), $Num($Co2, 1));
        }
        if ($this->opt('Previous')) {
            if ($Previous['total'] > 0) {
                $Lines[] = sprintf($this->previousText($Period), $Signed($ChangePrevious));
            }
            if ($AverageTotal > 0) {
                $Lines[] = sprintf($this->Translate('%s %% compared with the average of the last %d days'), $Signed($ChangeAverage), $Lookback);
            }
        }
        if ($LastYear !== null && $LastYear['total'] > 0) {
            $Lines[] = sprintf($this->Translate('%s %% compared with the same period last year'), $Signed($ChangeLastYear));
        }
        if ($this->opt('Notice') && $Notice !== '') {
            $Lines[] = $Notice;
        }
        if ($this->opt('Indicators')) {
            if ($Config['Residents'] > 0) {
                $Lines[] = sprintf($this->Translate('Per resident: %s %s'), $Num($Total / $Config['Residents'], 2), $Unit);
            }
            if ($Config['Area'] > 0) {
                $Lines[] = sprintf($this->Translate('Per m²: %s %s'), $Num($Total / $Config['Area'], 2), $Unit);
            }
        }
        if ($Rows !== []) {
            $Lines[] = '';
            foreach ($Rows as $Row) {
                $Line = str_repeat('  ', $Row['level']) . $Row['name'] . ': ' . $Num($Row['value'], 2) . ' ' . $Unit . ' (' . $Num($Row['share'], 0) . ' %)';
                if ($WithCost) {
                    $Line .= ', ' . $Num($Row['cost'], 2) . ' EUR';
                }
                $Lines[] = $Line;
            }
        }
        $Text = implode("\n", $Lines);

        $Csv = '';
        if ($Write && $this->opt('CSV')) {
            // Ohne Zeilen (zum Beispiel bei der Heizung) steht eine Zeile mit der Summe in der Datei
            $Summary = [
                'name'     => $Config['MainMeter'] > 0 ? IPS_GetName($Config['MainMeter']) : (string) $this->opt('Name'),
                'value'    => $Total,
                'cost'     => $Cost,
                'previous' => $Previous['total'] > 0 ? $Previous['total'] : null,
                'co2'      => $Co2
            ];
            $Csv = $this->buildCsv($Label, $Rows, $Unit, $Co2Factor, $Summary);
        }

        $Series = $this->seriesFor($Config, $ArchiveID, $Period, $Range);
        $Slices = $this->buildSlices($Nodes, (float) $Total);
        $TileRows = [];
        foreach ($Rows as $Row) {
            $TileRows[] = [
                'level'  => $Row['level'],
                'type'   => $Row['type'],
                'name'   => $Row['name'],
                'value'  => round($Row['value'], 3),
                'share'  => round($Row['share'], 1),
                'cost'   => round($Row['cost'], 2),
                'change' => ($Row['previous'] !== null && $Row['previous'] > 0) ? round(($Row['value'] - $Row['previous']) / $Row['previous'] * 100, 1) : null
            ];
        }
        $this->saveReportData((string) $this->opt('Name'), [
            'label'          => $Label,
            'unit'           => $Unit,
            'total'          => round($Total, 3),
            'cost'           => round($Cost, 2),
            'previous'       => $Previous['total'] > 0 ? round($Previous['total'], 3) : null,
            'average'        => $AverageTotal > 0 ? round($AverageTotal, 3) : null,
            'lastYear'       => ($LastYear !== null && $LastYear['total'] > 0) ? round($LastYear['total'], 3) : null,
            'changePrevious' => round($ChangePrevious, 1),
            'changeAverage'  => round($ChangeAverage, 1),
            'changeLastYear' => ($LastYear !== null && $LastYear['total'] > 0) ? round($ChangeLastYear, 1) : null,
            'lookback'       => $Lookback,
            'notice'         => $Notice,
            'perResident'    => $Config['Residents'] > 0 ? round($Total / $Config['Residents'], 3) : null,
            'perArea'        => $Config['Area'] > 0 ? round($Total / $Config['Area'], 3) : null,
            'co2'            => $Co2 !== null ? round($Co2, 2) : null,
            'series'         => $Series,
            'bucket'         => ['hour', 'day', 'day', 'month'][$this->bucketKind($Period, $Range)],
            'average_bucket' => count($Series) > 0 && $AverageTotal > 0 ? round($AverageTotal / count($Series), 4) : null,
            'slices'         => $Slices,
            'rows'           => $TileRows
        ]);

        $Pdf = '';
        if ($Write && $this->opt('PDF')) {
            $Pdf = $this->buildPdf([
                'name'           => (string) $this->opt('Name'),
                'label'          => $Label,
                'unit'           => $Unit,
                'total'          => $Total,
                'cost'           => $Cost,
                'previous'       => $Previous['total'],
                'average'        => $AverageTotal,
                'lastYear'       => $LastYear['total'] ?? null,
                'changePrevious' => $ChangePrevious,
                'changeAverage'  => $ChangeAverage,
                'changeLastYear' => $ChangeLastYear,
                'notice'         => $Notice,
                'nodes'          => $Nodes,
                'allRows'        => $AllRows,
                'rows'           => $Rows,
                'residents'      => $Config['Residents'],
                'area'           => $Config['Area'],
                'co2'            => $Co2,
                'lookback'       => $Lookback,
                'period'         => $Period,
                'series'         => $Series,
                'slices'         => $Slices,
                'bucket'         => ['hour', 'day', 'day', 'month'][$this->bucketKind($Period, $Range)]
            ]);
        }

        // Die Berichte liegen nur als Medienobjekte vor, auch der Mailanhang kommt von dort
        $PdfMedia = 0;
        $CsvMedia = 0;
        if ($Write) {
            $Name = (string) $this->opt('Name');
            $PdfMedia = $this->storeMedia($this->mediaIdent($Name, 'PDF'), $Name . ' (PDF)', $Pdf, 'pdf');
            $CsvMedia = $this->storeMedia($this->mediaIdent($Name, 'CSV'), $Name . ' (CSV)', $Csv, 'csv');
        }

        $this->SetValue('ReportText', $Text);
        $this->SetValue('ReportPeriod', $Label);
        $this->SetValue('Total', round($Total, 3));
        $this->SetValue('Cost', round($Cost, 2));
        $this->SetValue('ChangePrevious', round($ChangePrevious, 1));
        if ($UseLastYear) {
            $this->SetValue('ChangeLastYear', round($ChangeLastYear, 1));
        }
        $this->SetValue('ChangeAverage', round($ChangeAverage, 1));
        $this->SetValue('Notice', $Notice);

        $this->UpdateVisualizationValue($this->tileMessage(''));

        return ['text' => $Text, 'label' => $Label, 'media' => $PdfMedia > 0 ? $PdfMedia : $CsvMedia, 'total' => $Total, 'cost' => $Cost];
    }

    /**
     * CO2 des Gesamtverbrauchs in kg, wie in der CO2Bilanz: mit Faktor-Variable zählt jede Stunde mit ihrem Faktor, sonst gilt der feste Faktor
     * des Mediums. Null ohne Faktor.
     */
    private function reportCo2(array $Config, int $ArchiveID, array $Range, float $Total): ?float
    {
        $Fixed = (float) $Config['EmissionFactor'];
        $Factors = $this->factorHours($Config, $ArchiveID, $Range['start'], $Range['end']) ?? [];
        if ($Factors === [] || $Config['Unit']['Family'] !== 'energy') {
            return $Fixed > 0 ? $Total * $Fixed / 1000 : null;
        }
        $Level = ($Range['end'] - $Range['start']) <= 2 * 86400 ? 0 : 1;
        $IDs = [];
        if ($Config['MainMeter'] > 0) {
            $IDs[] = $Config['MainMeter'];
        } else {
            foreach ($Config['Consumers'] as $ID => $Consumer) {
                if ($Consumer['Group'] === '') {
                    $IDs[] = (int) $ID;
                }
            }
        }
        $Sum = 0.0;
        foreach ($IDs as $ID) {
            $Result = $this->co2Of($Config, $ArchiveID, $ID, $Range['start'], $Range['end'], $Level, $Factors, $Fixed);
            $Sum += $Result === null ? 0.0 : $Result['kg'];
        }
        return $Sum;
    }

    /**
     * Anteile der obersten Ebene für das Ringdiagramm und die Kachel: [Name, Wert, Farbe]. Der Rest gegenüber dem Gesamtverbrauch folgt als "Nicht erfasst".
     */
    private function buildSlices(array $Nodes, float $Total): array
    {
        $Slices = [];
        foreach ($Nodes as $Index => $Node) {
            if ($Node['value'] > 0) {
                $Slices[] = [$Node['name'], (float) $Node['value'], ($Node['color'] ?? '') !== '' ? $Node['color'] : ConsumptionReportPdf::PALETTE[$Index % count(ConsumptionReportPdf::PALETTE)]];
            }
        }
        $Listed = array_sum(array_column($Slices, 1));
        if ($Total > $Listed && $Listed > 0) {
            $Slices[] = [$this->Translate('Not recorded'), $Total - $Listed, '#CBD5E1'];
        }
        return $Slices;
    }

    /**
     * Verbrauch aller Variablen im Bereich mit Baum und Gesamtverbrauch (Hauptzähler, sonst Summe der obersten Ebene).
     */
    private function evaluateTotal(array $Config, int $ArchiveID, int $Start, int $End, array &$Skipped): array
    {
        $Values = $this->evaluate($Config, $ArchiveID, $Start, $End, 1, $Skipped);
        $Nodes = $this->buildTree('', $Config, $Values);
        $Main = $Config['MainMeter'] > 0 ? ($Values[$Config['MainMeter']] ?? null) : null;
        return ['nodes' => $Nodes, 'total' => $Main ?? array_sum(array_column($Nodes, 'value'))];
    }

    /**
     * Zeilen des Berichts: Gruppen mit ihren Mitgliedern, eingerückt nach Ebene.
     */
    private function collectRows(array $Nodes, int $Level, float $Total, float $PricePerUnit, array $PrevMap, array &$Rows): void
    {
        foreach ($Nodes as $Node) {
            $Rows[] = [
                'level'    => $Level,
                'type'     => $Node['type'],
                'name'     => $Node['name'],
                'value'    => $Node['value'],
                'share'    => $Total > 0 ? $Node['value'] / $Total * 100 : 0.0,
                'cost'     => $Node['value'] * $PricePerUnit,
                'previous' => $PrevMap[$Node['id']] ?? null
            ];
            $this->collectRows($Node['children'], $Level + 1, $Total, $PricePerUnit, $PrevMap, $Rows);
        }
    }

    // ---------------------------------------------------------------- Zeiträume

    /**
     * Die letzte volle Periode und die davor, außerdem dieselbe Periode ein Jahr früher.
     */
    private function periodRange(int $Period): array
    {
        $Today = new DateTimeImmutable('today');
        switch ($Period) {
            case self::PERIOD_WEEK:
                $End = $Today->modify('monday this week');
                $Start = $End->modify('-7 days');
                $Prev = $Start->modify('-7 days');
                break;
            case self::PERIOD_MONTH:
                $End = $Today->modify('first day of this month');
                $Start = $End->modify('-1 month');
                $Prev = $Start->modify('-1 month');
                break;
            case self::PERIOD_YEAR:
                $End = $Today->modify('first day of january this year');
                $Start = $End->modify('-1 year');
                $Prev = $Start->modify('-1 year');
                break;
            case self::PERIOD_CUSTOM:
                $Start = $Today->setTimestamp((int) ($this->custom['start'] ?? $Today->getTimestamp() - 86400));
                $End = $Today->setTimestamp((int) ($this->custom['end'] ?? $Today->getTimestamp()));
                // Die Vergleichsperiode ist der gleich lange Zeitraum direkt davor
                $Prev = $Start->setTimestamp(2 * $Start->getTimestamp() - $End->getTimestamp());
                break;
            default:
                $End = $Today;
                $Start = $End->modify('-1 day');
                $Prev = $Start->modify('-1 day');
                break;
        }
        return [
            'start'     => $Start->getTimestamp(),
            'end'       => $End->getTimestamp(),
            'prevStart' => $Prev->getTimestamp(),
            'yearStart' => $Start->modify('-1 year')->getTimestamp(),
            'yearEnd'   => $End->modify('-1 year')->getTimestamp()
        ];
    }

    /**
     * Schlüssel für den Dateinamen, Tage für den Durchschnitt davor und Schwelle des Hinweises. Beim eigenen Zeitraum
     * richten sich die Tage nach der Länge.
     */
    private function periodInfo(int $Period): array
    {
        if ($Period === self::PERIOD_CUSTOM) {
            $Days = max(1, (int) round((($this->custom['end'] ?? 0) - ($this->custom['start'] ?? 0)) / 86400));
            return ['key' => 'custom', 'lookback' => min(365, max(14, $Days * 2)), 'threshold' => 0.25];
        }
        return self::PERIODS[$Period];
    }

    private function periodLabel(int $Period, int $Start, int $End): string
    {
        switch ($Period) {
            case self::PERIOD_CUSTOM:
                return date('d.m.Y', $Start) . ' - ' . date('d.m.Y', $End - 1);
            case self::PERIOD_WEEK:
                return sprintf($this->Translate('Week %s'), date('W/o', $Start));
            case self::PERIOD_MONTH:
                return date('m/Y', $Start);
            case self::PERIOD_YEAR:
                return date('Y', $Start);
            default:
                return date('d.m.Y', $Start);
        }
    }

    private function previousText(int $Period): string
    {
        switch ($Period) {
            case self::PERIOD_CUSTOM:
                return $this->Translate('%s %% compared with the previous period');
            case self::PERIOD_WEEK:
                return $this->Translate('%s %% compared with the week before');
            case self::PERIOD_MONTH:
                return $this->Translate('%s %% compared with the month before');
            case self::PERIOD_YEAR:
                return $this->Translate('%s %% compared with the year before');
            default:
                return $this->Translate('%s %% compared with the day before');
        }
    }

    // ---------------------------------------------------------------- Kachel

    /**
     * Schickt die Berichtsdatei als Base64 an die Kachel, die sie direkt als Download speichert.
     */
    private function sendDownload(string $Type): void
    {
        $Map = ['pdf' => ['PDF', 'application/pdf'], 'csv' => ['CSV', 'text/csv;charset=utf-8']];
        $Profile = $this->selectedReport();
        if (!isset($Map[$Type]) || $Profile === null) {
            return;
        }
        $ID = (int) @$this->GetIDForIdent($this->mediaIdent($Profile['Name'], $Map[$Type][0]));
        if ($ID <= 0) {
            return;
        }
        $Label = (string) ($this->reportData($Profile['Name'])['label'] ?? '');
        $Name = self::FILE_PREFIX . trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $Profile['Name'] . '_' . $Label), '_') . '.' . $Type;
        $this->UpdateVisualizationValue((string) json_encode(['download' => ['name' => $Name, 'mime' => $Map[$Type][1], 'data' => IPS_GetMediaContent($ID)]]));
    }

    /**
     * Übernimmt eine Einstellung zum Inhalt aus der Kachel in die Zeile des gewählten Berichts. Nur bekannte Felder mit Werten in den Grenzen.
     */
    private function setOption(string $Json): void
    {
        $Data = json_decode($Json, true);
        $Key = is_array($Data) ? ($Data['key'] ?? null) : null;
        $Value = is_array($Data) ? ($Data['value'] ?? null) : null;
        $Profile = $this->selectedReport();
        if (!is_string($Key) || !isset(self::OPTIONS[$Key]) || $Profile === null) {
            $this->SendDebug(__FUNCTION__, 'Unbekannte Einstellung', 0);
            return;
        }
        $Rule = self::OPTIONS[$Key];
        if ($Rule[1] === 'bool') {
            if (!is_bool($Value)) {
                return;
            }
        } else {
            if (!is_numeric($Value)) {
                return;
            }
            $Value = max($Rule[2], min($Rule[3], (int) $Value));
        }
        // Die Zeile steht in der Liste des Mediums des Berichts
        $Property = $this->reportProperty((int) $Profile['Medium']);
        $Rows = json_decode($this->ReadPropertyString($Property), true);
        foreach (is_array($Rows) ? $Rows : [] as $Index => $Row) {
            if (is_array($Row) && trim((string) ($Row['Name'] ?? '')) === $Profile['Name']) {
                $Rows[$Index][$Rule[0]] = $Value;
                break;
            }
        }
        IPS_SetProperty($this->InstanceID, $Property, (string) json_encode($Rows));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Daten und übersetzte Texte für die Kachel. Die module.html enthält keine Texte einer Sprache.
     */
    private function tileMessage(string $Message): string
    {
        $Profile = $this->selectedReport() ?? (self::DEFAULT_REPORT + ['Name' => '']);
        return (string) json_encode([
            'period'         => (int) $Profile['Period'],
            'canMail'        => $this->isSMTP($this->ReadPropertyInteger('SMTPInstanceID')),
            'message'        => $Message,
            'reports'        => array_keys($this->reports()),
            'selectedReport' => $Profile['Name'],
            'options'        => [
                'IncludeCost'        => (bool) $Profile['Cost'],
                'IncludePrevious'    => (bool) $Profile['Previous'],
                'IncludeLastYear'    => (bool) $Profile['LastYear'],
                'IncludeNotice'      => (bool) $Profile['Notice'],
                'IncludeIndicators'  => (bool) $Profile['Indicators'],
                'HeatingMedium'      => (int) $Profile['Medium'] === self::MEDIUM_HEATING,
                'IncludeCo2'         => (bool) $Profile['Co2'],
                'IncludeNotRecorded' => (bool) $Profile['NotRecorded'],
                'ListMode'           => (int) $Profile['ListMode'],
                'TopN'               => (int) $Profile['TopN']
            ],
            'today'   => date('Y-m-d'),
            'report'  => $this->tileReport(),
            'texts'   => [
                'day'                => $this->Translate('Day'),
                'week'               => $this->Translate('Week'),
                'month'              => $this->Translate('Month'),
                'year'               => $this->Translate('Year'),
                'create'             => $this->Translate('Create report'),
                'mail'               => $this->Translate('Send by mail'),
                'pdf'                => $this->Translate('Open PDF'),
                'csv'                => $this->Translate('Open CSV'),
                'total'              => $this->Translate('Total consumption'),
                'cost'               => $this->Translate('Cost'),
                'previous'           => $this->Translate('To the previous period'),
                'average'            => $this->Translate('To the average'),
                'empty'              => $this->Translate('No report yet, choose a period and create one'),
                'busy'               => $this->Translate('Creating the report'),
                'custom'             => $this->Translate('Custom'),
                'from'               => $this->Translate('From'),
                'to'                 => $this->Translate('To'),
                'content'            => $this->Translate('Content'),
                'close'              => $this->Translate('Close'),
                'lastYear'           => $this->Translate('To the same period last year'),
                'perResident'        => $this->Translate('Per resident'),
                'perArea'            => $this->Translate('Per m²'),
                'co2'                => $this->Translate('CO2'),
                'course'             => $this->Translate('Course'),
                'axisHour'           => $this->Translate('Hour'),
                'axisDay'            => $this->Translate('Day'),
                'axisMonth'          => $this->Translate('Month'),
                'distribution'       => $this->Translate('Distribution'),
                'details'            => $this->Translate('Details'),
                'IncludeCost'        => $this->Translate('Cost'),
                'IncludePrevious'    => $this->Translate('Comparison with the previous period and the average'),
                'IncludeLastYear'    => $this->Translate('Comparison with the same period last year (not for yearly reports)'),
                'IncludeNotice'      => $this->Translate('Notice for unusual consumption'),
                'IncludeIndicators'  => $this->Translate('Key figures per resident and per m²'),
                'IncludeCo2'         => $this->Translate('CO2'),
                'IncludeNotRecorded' => $this->Translate('Consumption not recorded (compared with the main meter)'),
                'ListMode'           => $this->Translate('List of consumers'),
                'listNone'           => $this->Translate('None'),
                'listTree'           => $this->Translate('Groups and devices'),
                'listTop'            => $this->Translate('Largest devices'),
                'TopN'               => $this->Translate('Number of devices (for the largest devices)')
            ]
        ]);
    }

    /**
     * Der zuletzt erstellte Bericht des gewählten Berichts der Liste für die Kachel, ergänzt um die Medienobjekte zum Download. Null ohne Bericht.
     */
    private function tileReport(): ?array
    {
        $Profile = $this->selectedReport();
        $Data = $Profile === null ? null : $this->reportData($Profile['Name']);
        if ($Data === null) {
            return null;
        }
        $Data['pdf'] = (int) @$this->GetIDForIdent($this->mediaIdent($Profile['Name'], 'PDF'));
        $Data['csv'] = (int) @$this->GetIDForIdent($this->mediaIdent($Profile['Name'], 'CSV'));
        return $Data;
    }

    private function saveReportData(string $Name, array $Data): void
    {
        $All = $this->allReportData();
        $All[$Name] = $Data;
        $this->SetValue('Data', (string) json_encode($All));
    }

    // ---------------------------------------------------------------- PDF

    /**
     * Teilung des Verlaufsdiagramms als Periodenart: Stunden (Tag), Tage (Woche, Monat) oder Monate (Jahr). Beim eigenen Zeitraum
     * richtet sie sich nach der Länge: Stunden bis zwei Tage, Tage bis zwei Monate, sonst Monate.
     */
    private function bucketKind(int $Period, array $Range): int
    {
        if ($Period !== self::PERIOD_CUSTOM) {
            return $Period;
        }
        $Days = ($Range['end'] - $Range['start']) / 86400;
        return $Days <= 2 ? self::PERIOD_DAY : ($Days <= 14 ? self::PERIOD_WEEK : ($Days <= 62 ? self::PERIOD_MONTH : self::PERIOD_YEAR));
    }

    /**
     * Verbrauch je Abschnitt der Periode für das Verlaufsdiagramm: Stunden beim Tag, Tage bei Woche und Monat, Monate beim Jahr.
     * Grundlage ist der Hauptzähler, ohne ihn die Summe aller Verbraucher.
     *
     * @return array<int, array{label: string, value: float}>
     */
    private function seriesFor(array $Config, int $ArchiveID, int $Period, array $Range): array
    {
        $IDs = $Config['MainMeter'] > 0 ? [$Config['MainMeter']] : array_keys($Config['Consumers']);
        $Skipped = [];
        $Series = [];
        $Cursor = (new DateTimeImmutable('today'))->setTimestamp($Range['start']);
        $Kind = $this->bucketKind($Period, $Range);
        $Level = $Kind === self::PERIOD_DAY ? 0 : 1;
        while ($Cursor->getTimestamp() < $Range['end']) {
            switch ($Kind) {
                case self::PERIOD_DAY:
                    $Next = $Cursor->modify('+1 hour');
                    $Label = $Cursor->format('H');
                    $Title = $Cursor->format('d.m.Y H:00');
                    break;
                case self::PERIOD_YEAR:
                    $Next = $Cursor->modify('+1 month');
                    $Label = $Cursor->format('n');
                    $Title = $Cursor->format('m/Y');
                    break;
                case self::PERIOD_WEEK:
                    $Next = $Cursor->modify('+1 day');
                    $Label = $Cursor->format('d.m.');
                    $Title = $Cursor->format('d.m.Y');
                    break;
                default:
                    $Next = $Cursor->modify('+1 day');
                    $Label = $Cursor->format('j');
                    $Title = $Cursor->format('d.m.Y');
                    break;
            }
            $Value = 0.0;
            foreach ($IDs as $ID) {
                $Value += $this->consumption($Config, $ArchiveID, (int) $ID, $Cursor->getTimestamp(), min($Next->getTimestamp(), $Range['end']), $Level, $Skipped) ?? 0.0;
            }
            $Series[] = ['label' => $Label, 'title' => $Title, 'value' => $Value];
            $Cursor = $Next;
        }
        return $Series;
    }

    /**
     * Baut das PDF des Berichts mit den Einstellungen zum Inhalt.
     */
    private function buildPdf(array $D): string
    {
        $Options = [
            'DecimalComma'      => $this->ReadPropertyBoolean('DecimalComma'),
            'IncludeCost'       => (bool) $this->opt('Cost'),
            'IncludePrevious'   => (bool) $this->opt('Previous'),
            'IncludeIndicators' => (bool) $this->opt('Indicators'),
            'IncludeNotice'     => (bool) $this->opt('Notice')
        ];
        $Pdf = new ConsumptionReportPdf(fn (string $Key): string => $this->Translate($Key), $Options);
        return $Pdf->build($D);
    }

    // ---------------------------------------------------------------- CSV

    /**
     * Baut die Zeilen als CSV (Semikolon, UTF-8 mit Bytemarke für Excel).
     */
    private function buildCsv(string $Label, array $Rows, string $Unit, float $Co2Factor, array $Summary): string
    {
        $Comma = $this->ReadPropertyBoolean('DecimalComma');
        $Num = fn (?float $Value, int $Digits): string => $Value === null ? '' : number_format($Value, $Digits, $Comma ? ',' : '.', '');
        $Quote = fn (string $Text): string => '"' . str_replace('"', '""', $Text) . '"';

        $Csv = "\xEF\xBB\xBF" . implode(';', [
            $this->Translate('Period'), $this->Translate('Level'), $this->Translate('Name'), $this->Translate('Consumption'), $this->Translate('Unit'),
            $this->Translate('Share in %'), $this->Translate('Cost in EUR'), $this->Translate('Previous period'), $this->Translate('Change in %'), $this->Translate('CO2 in kg')
        ]) . "\r\n";
        foreach ($Rows as $Row) {
            $Change = ($Row['previous'] !== null && $Row['previous'] > 0) ? ($Row['value'] - $Row['previous']) / $Row['previous'] * 100 : null;
            $Csv .= implode(';', [
                $Quote($Label), (string) $Row['level'], $Quote($Row['name']), $Num($Row['value'], 3), $Quote($Unit),
                $Num($Row['share'], 1), $Num($Row['cost'], 2), $Num($Row['previous'], 3), $Num($Change, 1),
                $Co2Factor > 0 ? $Num($Row['value'] * $Co2Factor / 1000, 2) : ''
            ]) . "\r\n";
        }

        if ($Rows === []) {
            $Change = $Summary['previous'] !== null ? ($Summary['value'] - $Summary['previous']) / $Summary['previous'] * 100 : null;
            $Csv .= implode(';', [
                $Quote($Label), '0', $Quote((string) $Summary['name']), $Num($Summary['value'], 3), $Quote($Unit),
                $Num(100.0, 1), $Num($Summary['cost'], 2), $Num($Summary['previous'], 3), $Num($Change, 1),
                $Num($Summary['co2'], 2)
            ]) . "\r\n";
        }

        return $Csv;
    }

    /**
     * Legt den Bericht als Medienobjekt ab, das sich in der Visualisierung herunterladen lässt. Es gibt je Bericht und Dateityp ein Objekt, das
     * beim nächsten Bericht überschrieben wird. Gibt die ID des Objekts zurück (für den Mailanhang), 0 ohne Inhalt.
     */
    private function storeMedia(string $Ident, string $Name, string $Content, string $Extension): int
    {
        if ($Content === '') {
            return 0;
        }
        $ID = @$this->GetIDForIdent($Ident);
        if (!$ID) {
            $ID = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
            IPS_SetParent($ID, $this->InstanceID);
            IPS_SetIdent($ID, $Ident);
            IPS_SetMediaFile($ID, 'media/' . self::FILE_PREFIX . $this->InstanceID . '_' . $Ident . '.' . $Extension, false);
        }
        IPS_SetName($ID, $Name);
        IPS_SetMediaContent($ID, base64_encode($Content));
        return (int) $ID;
    }

    /**
     * Entfernt die Medienobjekte von Berichten, die es nicht mehr gibt oder die das Format nicht mehr erstellen.
     */
    private function maintainMedia(): void
    {
        $Wanted = [];
        foreach ($this->reports() as $Name => $Profile) {
            foreach (['PDF', 'CSV'] as $Kind) {
                if ($Profile[$Kind]) {
                    $Wanted[$this->mediaIdent($Name, $Kind)] = true;
                }
            }
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $ID) {
            if (IPS_MediaExists($ID) && !isset($Wanted[IPS_GetObject($ID)['ObjectIdent']])) {
                IPS_DeleteMedia($ID, true);
            }
        }
    }

    // ---------------------------------------------------------------- Mail

    /**
     * Schickt den Bericht an den Empfänger des Berichts, ohne Empfänger an die Standardadresse der SMTP-Instanz. PDF oder CSV hängen als Medienobjekt an.
     */
    private function sendMail(array $Report, array $Profile): bool
    {
        $SMTP = $this->ReadPropertyInteger('SMTPInstanceID');
        if (!$this->isSMTP($SMTP)) {
            $this->LogMessage('No valid SMTP instance selected, the report was not sent', KL_WARNING);
            return false;
        }
        $Custom = trim((string) $Profile['Subject']);
        $Subject = $Custom === ''
            ? sprintf($this->Translate('Consumption report %s'), $Profile['Name'] . ' ' . $Report['label'])
            : str_replace(['{period}', '{name}'], [$Report['label'], $Profile['Name']], $Custom);
        $Recipient = trim((string) $Profile['Recipient']);
        if ($Report['media'] > 0) {
            if ($Recipient !== '') {
                return function_exists('SMTP_SendMailMediaEx') && (bool) SMTP_SendMailMediaEx($SMTP, $Recipient, $Subject, $Report['text'], $Report['media']);
            }
            return function_exists('SMTP_SendMailMedia') && (bool) SMTP_SendMailMedia($SMTP, $Subject, $Report['text'], $Report['media']);
        }
        if ($Recipient !== '') {
            return function_exists('SMTP_SendMailEx') && (bool) SMTP_SendMailEx($SMTP, $Recipient, $Subject, $Report['text']);
        }
        return function_exists('SMTP_SendMail') && (bool) SMTP_SendMail($SMTP, $Subject, $Report['text']);
    }

    private function isSMTP(int $ID): bool
    {
        return $ID > 0 && IPS_InstanceExists($ID) && IPS_GetInstance($ID)['ModuleInfo']['ModuleID'] === self::SMTP_MODULE_ID;
    }

    // ---------------------------------------------------------------- Zeitplan und Prüfung

    /**
     * Die nächsten Zeitpunkte aller Berichte mit automatischer Erstellung, nach Name des Berichts.
     *
     * @return array<string, int>
     */
    private function scheduleAll(): array
    {
        $Runs = [];
        foreach ($this->reports() as $Name => $Profile) {
            if ($Profile['Auto']) {
                $Runs[$Name] = $this->nextRun($Profile);
            }
        }
        return $Runs;
    }

    /**
     * Der nächste Berichtszeitpunkt: täglich zur Uhrzeit, wöchentlich am Wochentag, monatlich am Tag des Monats,
     * jährlich am Tag im Januar.
     */
    private function nextRun(array $Profile): int
    {
        $Period = (int) $Profile['Period'];
        $Time = sprintf('%02d:%02d', (int) $Profile['Hour'], (int) $Profile['Minute']);
        $Now = time();
        $Day = new DateTimeImmutable('today');
        for ($i = 0; $i <= 400; $i++) {
            $Date = $Day->modify('+' . $i . ' days');
            $Match = match ($Period) {
                self::PERIOD_WEEK  => (int) $Date->format('N') === (int) $Profile['Weekday'],
                self::PERIOD_MONTH => (int) $Date->format('j') === (int) $Profile['MonthDay'],
                self::PERIOD_YEAR  => (int) $Date->format('n') === 1 && (int) $Date->format('j') === (int) $Profile['MonthDay'],
                default            => true
            };
            if ($Match) {
                $Run = (int) strtotime($Date->format('Y-m-d') . ' ' . $Time);
                if ($Run > $Now) {
                    return $Run;
                }
            }
        }
        return 0;
    }

    private function armTimer(): void
    {
        $Runs = json_decode($this->ReadAttributeString('NextRuns'), true);
        $Future = array_filter(is_array($Runs) ? array_map('intval', $Runs) : [], fn (int $Run): bool => $Run > 0);
        $Next = $Future === [] ? 0 : min($Future);
        if ($Next <= 0) {
            $this->SetTimerInterval('Schedule', 0);
            return;
        }
        $this->SetTimerInterval('Schedule', min(max(1, $Next - time()), self::MAX_TICK_SECONDS) * 1000);
    }

    private function validate(): string
    {
        $Rows = $this->reportRows();
        if ($Rows === []) {
            return 'Die Berichtsliste ist leer';
        }
        $Names = [];
        $NeedsSmtp = false;
        foreach ($Rows as $Profile) {
            $Name = $Profile['Name'];
            if ($Name === '') {
                return 'Ein Bericht hat keinen Namen';
            }
            if (isset($Names[$Name])) {
                return 'Der Name des Berichts kommt doppelt vor: ' . $Name;
            }
            $Names[$Name] = true;
            $Period = (int) $Profile['Period'];
            if (!isset(self::PERIODS[$Period])) {
                return 'Der Zeitraum von ' . $Name . ' ist ungültig';
            }
            if ((int) $Profile['Hour'] < 0 || (int) $Profile['Hour'] > 23 || (int) $Profile['Minute'] < 0 || (int) $Profile['Minute'] > 59) {
                return 'Die Berichtszeit von ' . $Name . ' ist ungültig';
            }
            if ($Period === self::PERIOD_WEEK && ((int) $Profile['Weekday'] < 1 || (int) $Profile['Weekday'] > 7)) {
                return 'Der Wochentag von ' . $Name . ' ist ungültig';
            }
            if (($Period === self::PERIOD_MONTH || $Period === self::PERIOD_YEAR) && ((int) $Profile['MonthDay'] < 1 || (int) $Profile['MonthDay'] > 28)) {
                return 'Der Tag im Monat von ' . $Name . ' muss zwischen 1 und 28 liegen';
            }
            if ((int) $Profile['ListMode'] < self::LIST_NONE || (int) $Profile['ListMode'] > self::LIST_TOP || (int) $Profile['TopN'] < 1 || (int) $Profile['TopN'] > 100) {
                return 'Die Geräteliste von ' . $Name . ' ist ungültig';
            }
            if ($Profile['Mail']) {
                $NeedsSmtp = true;
                foreach (preg_split('/[,;]\s*/', trim((string) $Profile['Recipient']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $Address) {
                    if (filter_var($Address, FILTER_VALIDATE_EMAIL) === false) {
                        return 'Die Empfängeradresse von ' . $Name . ' ist ungültig: ' . $Address;
                    }
                }
            }
        }
        if ($NeedsSmtp && !$this->isSMTP($this->ReadPropertyInteger('SMTPInstanceID'))) {
            return 'Für den Versand ist keine gültige SMTP-Instanz gewählt';
        }
        return '';
    }

    private function maintainVariables(): void
    {
        $Value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Percent = $Value + ['SUFFIX' => ' %', 'DIGITS' => 1];
        $LastYear = false;
        foreach ($this->reports() as $Profile) {
            $LastYear = $LastYear || ($Profile['LastYear'] && (int) $Profile['Period'] !== self::PERIOD_YEAR);
        }
        $this->MaintainVariable('ReportText', $this->Translate('Report'), VARIABLETYPE_STRING, $Value, 1, true);
        $this->MaintainVariable('ReportPeriod', $this->Translate('Period of the report'), VARIABLETYPE_STRING, $Value, 2, true);
        $this->MaintainVariable('Total', $this->Translate('Total consumption'), VARIABLETYPE_FLOAT, $Value + ['DIGITS' => 2], 3, true);
        $this->MaintainVariable('Cost', $this->Translate('Cost'), VARIABLETYPE_FLOAT, $Value + ['SUFFIX' => ' €', 'DIGITS' => 2], 4, true);
        $this->MaintainVariable('ChangePrevious', $this->Translate('Change to the previous period'), VARIABLETYPE_FLOAT, $Percent, 5, true);
        $this->MaintainVariable('ChangeLastYear', $this->Translate('Change to the same period last year'), VARIABLETYPE_FLOAT, $Percent, 6, $LastYear);
        $this->MaintainVariable('ChangeAverage', $this->Translate('Change to the average'), VARIABLETYPE_FLOAT, $Percent, 7, true);
        $this->MaintainVariable('Notice', $this->Translate('Notice'), VARIABLETYPE_STRING, $Value, 8, true);
        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, $Value, 11, true);
        $this->hideVariable('Data');
    }
}
