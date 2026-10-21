<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ArchiveConsumptionStrict.php';
require_once __DIR__ . '/../libs/SourceChildStrict.php';
require_once __DIR__ . '/../libs/ConsumptionTreeStrict.php';
require_once __DIR__ . '/../libs/CustomPeriodStrict.php';

/**
 * Verbrauchsfluss: Der Verbrauch aus den Verbrauchsquellen als Sankey-Diagramm in der Kachel. Vom Hauptzähler über die Gruppen zu den
 * Geräten, dazu der nicht erfasste Rest. Der Zeitraum (heute, Woche, Monat, Jahr oder eigener Zeitraum) wird in der Kachel gewählt.
 */
class Verbrauchsfluss extends IPSModuleStrict
{
    use ArchiveConsumption;
    use SourceChild;
    use ConsumptionTree;
    use CustomPeriod;

    private const PERIOD_TODAY = 0;
    private const PERIOD_WEEK = 1;
    private const PERIOD_MONTH = 2;
    private const PERIOD_YEAR = 3;
    private const PERIOD_CUSTOM = 4;
    private const PERIOD_LIVE = 5;

    // Live: so lange hält die Kachel die Live-Anzeige ohne Lebenszeichen, und so weit reicht der Blick zurück bei Zählern
    private const LIVE_KEEP_SECONDS = 180;
    private const LIVE_WINDOWS = [1800, 7200];

    private const PALETTE = ['#12B69C', '#E08A1E', '#6366F1', '#D64545', '#0EA5E9', '#B45309', '#8B5CF6', '#64748B'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('Period', self::PERIOD_MONTH);
        $this->RegisterPropertyInteger('MinShare', 2);
        $this->RegisterPropertyBoolean('IncludeNotRecorded', true);
        $this->RegisterPropertyBoolean('ShowCost', true);
        $this->RegisterPropertyBoolean('OpenChartOnClick', true);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('LiveInterval', 15);

        $this->RegisterAttributeString('LastRequest', '');
        $this->RegisterAttributeInteger('LiveUntil', 0);
        $this->RegisterTimer('Refresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Refresh\', \'\');');
        $this->registerSourceChild();

        // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1, auf 9.0 steht für HTML der Wert 1 aus der Doku.
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
        return $Html . '<script>handleMessage(' . json_encode($this->tileMessage('')) . ');</script>';
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

        $this->MaintainVariable('Data', $this->Translate('Evaluation data'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1, true);
        $this->hideVariable('Data');

        $Sources = $this->requestSources();
        if ($Sources === null) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Splitter Verbrauchsquellen verbunden', 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(self::STATUS_NO_SOURCES);
            return;
        }

        if ($this->mediumEmpty($Sources)) {
            $this->SendDebug(__FUNCTION__, 'Das gewählte Medium ist nicht eingerichtet', 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(IS_INACTIVE);
            $this->SetValue('Data', '');
            $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The selected medium is not set up in the Consumption sources')));
            return;
        }

        $Error = $this->validate();
        if ($Error !== '') {
            $this->SendDebug(__FUNCTION__, 'Konfiguration ungültig: ' . $Error, 0);
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->armTimer();
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->refresh();
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
        if ($Ident === 'Keep') {
            // Lebenszeichen der Kachel, solange sie die Live-Anzeige zeigt
            if ($this->currentRequest()['period'] === self::PERIOD_LIVE) {
                $this->WriteAttributeInteger('LiveUntil', time() + self::LIVE_KEEP_SECONDS);
            }
            return;
        }
        if ($Ident === 'Refresh') {
            $this->refresh();
            return;
        }
        if ($Ident === 'Load') {
            $Request = $this->parseRequest($Value);
            if ($Request === null) {
                $this->UpdateVisualizationValue($this->tileMessage($this->Translate('The period is invalid')));
                return;
            }
            $this->WriteAttributeString('LastRequest', (string) json_encode($Request));
            $this->refresh();
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Unbekannte Aktion ' . $Ident, 0);
    }

    /**
     * Berechnet das Diagramm für den Zeitraum der Kachel neu und schickt es an die Kachel.
     */
    public function Update(): void
    {
        $this->refresh();
    }

    // ---------------------------------------------------------------- Berechnung

    /**
     * Zeitraum aus der Kachel: eine Zahl für die festen Zeiträume oder {"from", "to"} für den eigenen. Gibt ['period' => ..., 'custom' => ...] zurück.
     */
    private function parseRequest(mixed $Value): ?array
    {
        if (is_string($Value) && str_starts_with($Value, '{')) {
            $Custom = $this->parseCustom($Value);
            return $Custom === null ? null : ['period' => self::PERIOD_CUSTOM, 'custom' => $Custom];
        }
        $Period = (int) $Value;
        if ($Period === self::PERIOD_LIVE) {
            $this->WriteAttributeInteger('LiveUntil', time() + self::LIVE_KEEP_SECONDS);
        }
        return (($Period >= self::PERIOD_TODAY && $Period <= self::PERIOD_YEAR) || $Period === self::PERIOD_LIVE) ? ['period' => $Period, 'custom' => null] : null;
    }

    private function currentRequest(): array
    {
        $Last = json_decode($this->ReadAttributeString('LastRequest'), true);
        if (is_array($Last) && isset($Last['period'])) {
            $Period = (int) $Last['period'];
            if ($Period === self::PERIOD_CUSTOM && is_array($Last['custom'] ?? null)) {
                return ['period' => $Period, 'custom' => $Last['custom']];
            }
            if ($Period >= self::PERIOD_TODAY && $Period <= self::PERIOD_YEAR) {
                return ['period' => $Period, 'custom' => null];
            }
            // Die Live-Anzeige läuft nur, solange die Kachel sie zeigt, sonst gilt wieder der eingestellte Zeitraum
            if ($Period === self::PERIOD_LIVE && $this->ReadAttributeInteger('LiveUntil') > time()) {
                return ['period' => $Period, 'custom' => null];
            }
        }
        return ['period' => $this->ReadPropertyInteger('Period'), 'custom' => null];
    }

    private function refresh(): void
    {
        if (IPS_GetKernelRunlevel() != KR_READY || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $Request = $this->currentRequest();
        $Data = $this->build($Request['period'], $Request['custom']);
        if ($Data !== null) {
            $this->SetValue('Data', (string) json_encode($Data));
        }
        $this->armTimer($Request['period'] === self::PERIOD_LIVE);
        $this->UpdateVisualizationValue($this->tileMessage(''));
    }

    /**
     * Der Timer aktualisiert den Zeitraum im eingestellten Intervall, die Live-Anzeige alle paar Sekunden.
     */
    private function armTimer(bool $Live = false): void
    {
        $this->SetTimerInterval('Refresh', $Live ? $this->ReadPropertyInteger('LiveInterval') * 1000 : $this->ReadPropertyInteger('UpdateInterval') * 60000);
    }

    /**
     * Beginn, Ende (ausschließlich) und Bezeichnung des Zeitraums. Die festen Zeiträume laufen bis jetzt.
     */
    private function periodRange(int $Period, ?array $Custom): array
    {
        $Today = new DateTimeImmutable('today');
        $Now = time();
        switch ($Period) {
            case self::PERIOD_WEEK:
                $Start = $Today->modify('monday this week')->getTimestamp();
                $End = $Now;
                $Label = sprintf($this->Translate('Week %s'), date('W/o', $Start));
                break;
            case self::PERIOD_MONTH:
                $Start = $Today->modify('first day of this month')->getTimestamp();
                $End = $Now;
                $Label = date('m/Y', $Start);
                break;
            case self::PERIOD_YEAR:
                $Start = $Today->modify('first day of january this year')->getTimestamp();
                $End = $Now;
                $Label = date('Y', $Start);
                break;
            case self::PERIOD_CUSTOM:
                $Start = (int) ($Custom['start'] ?? $Today->getTimestamp());
                $End = min((int) ($Custom['end'] ?? $Now), $Now);
                $Label = date('d.m.Y', $Start) . ' - ' . date('d.m.Y', (int) ($Custom['end'] ?? $Now) - 1);
                break;
            default:
                $Start = $Today->getTimestamp();
                $End = $Now;
                $Label = date('d.m.Y', $Start);
                break;
        }
        return ['start' => $Start, 'end' => $End, 'label' => $Label];
    }

    /**
     * Baut Knoten und Flüsse für den Zeitraum. Null, wenn die Quellen nicht auswertbar sind.
     */
    private function build(int $Period, ?array $Custom): ?array
    {
        $Config = $this->readConfiguration();
        if ($Config['NoSources'] || ($Config['Consumers'] === [] && $Config['MainMeter'] === 0)) {
            return null;
        }
        $ArchiveID = $this->getArchiveID();
        if ($ArchiveID === 0) {
            $this->SetStatus(self::STATUS_SOURCE_UNUSABLE);
            return null;
        }

        if ($Period === self::PERIOD_LIVE) {
            return $this->buildLive($Config, $ArchiveID);
        }

        $Range = $this->periodRange($Period, $Custom);
        $Skipped = [];
        // Bis zu zwei Tage aus den Stundenwerten, länger aus den Tageswerten
        $Level = ($Range['end'] - $Range['start']) <= 2 * 86400 ? 0 : 1;
        $Values = $this->evaluate($Config, $ArchiveID, $Range['start'], $Range['end'], $Level, $Skipped);
        $Nodes = $this->buildTree('', $Config, $Values);
        $Listed = array_sum(array_column($Nodes, 'value'));
        $Main = $Config['MainMeter'] > 0 ? ($Values[$Config['MainMeter']] ?? null) : null;
        $Total = $Main ?? $Listed;

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

        $Flow = ['nodes' => [], 'links' => []];
        if ($Total > 0) {
            $this->addNode($Flow, ['id' => 'root', 'name' => $this->Translate('Total'), 'value' => $Total, 'col' => 0, 'color' => '', 'type' => 'root', 'objectId' => (int) $Config['MainMeter']], $PricePerUnit);
            $Children = $Nodes;
            if ($this->ReadPropertyBoolean('IncludeNotRecorded') && $Config['MainMeter'] > 0 && $Total - $Listed > 0.0005) {
                $Children[] = ['id' => 'unrecorded', 'type' => 'unrecorded', 'name' => $this->Translate('Not recorded'), 'value' => $Total - $Listed, 'children' => []];
            }
            $this->addChildren($Flow, 'root', $Children, 1, '', $Total, $PricePerUnit);
        }

        return [
            'label'        => $Range['label'],
            'period'       => $Period,
            'unit'         => $Config['Unit']['Out'],
            'total'        => round($Total, 3),
            'cost'         => round($Cost, 2),
            'nodes'        => $Flow['nodes'],
            'links'        => $Flow['links'],
            'updated'      => time()
        ];
    }

    /**
     * Live-Ansicht: die aktuelle Leistung je Quelle in W (bei Volumen l/h). Leistungsvariablen zählen mit ihrem aktuellen Wert,
     * Zähler mit der mittleren Leistung der letzten halben Stunde, bei zu wenigen Werten der letzten zwei Stunden.
     */
    private function buildLive(array $Config, int $ArchiveID): ?array
    {
        if (str_starts_with($Config['Unit']['Family'], 'custom')) {
            return null;
        }
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
            $Power = $this->livePower($Config, $ArchiveID, (int) $ID);
            $Values[$ID] = $Power === null ? null : $Power * 1000;
        }

        $Nodes = $this->raiseToChildren($this->buildTree('', $Config, $Values));
        $Listed = array_sum(array_column($Nodes, 'value'));
        $Main = $Config['MainMeter'] > 0 ? ($Values[$Config['MainMeter']] ?? null) : null;
        // Meldet der Hauptzähler weniger als die Geräte zusammen (zum Beispiel ein Zähler ohne neue Werte), gilt die Summe der Geräte
        $Total = max($Main ?? $Listed, $Listed);

        // Kosten je Stunde bei der jetzigen Leistung: Verbrauch je Stunde in der Basiseinheit mal aktueller Preis
        $Sources = $this->requestSources();
        $Price = (float) ($Sources['PriceNow'] ?? $Config['FixedPrice']);
        $PricePerUnit = $Price / 1000;

        $Flow = ['nodes' => [], 'links' => []];
        if ($Total > 0) {
            $this->addNode($Flow, ['id' => 'root', 'name' => $this->Translate('Total'), 'value' => $Total, 'col' => 0, 'color' => '', 'type' => 'root', 'objectId' => (int) $Config['MainMeter']], $PricePerUnit);
            $Children = $Nodes;
            if ($this->ReadPropertyBoolean('IncludeNotRecorded') && $Config['MainMeter'] > 0 && $Total - $Listed > 0.5) {
                $Children[] = ['id' => 'unrecorded', 'type' => 'unrecorded', 'name' => $this->Translate('Not recorded'), 'value' => $Total - $Listed, 'children' => []];
            }
            $this->addChildren($Flow, 'root', $Children, 1, '', $Total, $PricePerUnit);
        }

        return [
            'label'    => $this->Translate('Live'),
            'period'   => self::PERIOD_LIVE,
            'live'     => true,
            'unit'     => $Config['Unit']['Family'] === 'energy' ? 'W' : 'l/h',
            'costUnit' => '€/h',
            'total'    => round($Total, 1),
            'cost'     => round($Total * $PricePerUnit, 3),
            'nodes'    => $Flow['nodes'],
            'links'    => $Flow['links'],
            'updated'  => time()
        ];
    }

    /**
     * Ein Gruppenzähler ohne neue Werte meldet live 0, obwohl die Geräte der Gruppe Leistung haben. Jeder Knoten zeigt mindestens
     * die Summe seiner Kinder.
     */
    private function raiseToChildren(array $Nodes): array
    {
        foreach ($Nodes as &$Node) {
            if (!empty($Node['children'])) {
                $Node['children'] = $this->raiseToChildren($Node['children']);
                $Node['value'] = max($Node['value'], array_sum(array_column($Node['children'], 'value')));
            }
        }
        unset($Node);
        return $Nodes;
    }

    /**
     * Aktuelle Leistung einer Quelle in der Basiseinheit je Stunde (kW oder m³/h). Null, wenn sie nicht auswertbar ist.
     */
    private function livePower(array $Config, int $ArchiveID, int $ID): ?float
    {
        if (!IPS_VariableExists($ID)) {
            return null;
        }
        $Info = $this->unitInfoFor($ID, $Config['Unit']);
        if ($Info['Family'] !== $Config['Unit']['Family']) {
            return null;
        }
        if ($Info['Kind'] === 'power') {
            return max(0.0, (float) GetValue($ID)) * $Info['Factor'];
        }
        if (!AC_GetLoggingStatus($ArchiveID, $ID)) {
            return null;
        }
        $Now = time();
        foreach (self::LIVE_WINDOWS as $Window) {
            // Die Werte kommen mit dem neuesten zuerst. Nur Anstiege zählen, ein Zähler-Reset verfälscht nichts.
            $Records = array_reverse(AC_GetLoggedValues($ArchiveID, $ID, $Now - $Window, $Now, 0));
            if (count($Records) < 2) {
                continue;
            }
            $Sum = 0.0;
            for ($i = 1; $i < count($Records); $i++) {
                $Delta = (float) $Records[$i]['Value'] - (float) $Records[$i - 1]['Value'];
                if ($Delta > 0) {
                    $Sum += $Delta;
                }
            }
            $Seconds = (int) $Records[count($Records) - 1]['TimeStamp'] - (int) $Records[0]['TimeStamp'];
            if ($Seconds > 0) {
                return $Sum * $Info['Factor'] * 3600 / $Seconds;
            }
        }
        return 0.0;
    }

    private function addNode(array &$Flow, array $Node, float $PricePerUnit): void
    {
        $Flow['nodes'][] = [
            'id'       => $Node['id'],
            'name'     => $Node['name'],
            'value'    => round($Node['value'], 3),
            'cost'     => round($Node['value'] * $PricePerUnit, 2),
            'col'      => $Node['col'],
            'color'    => $Node['color'],
            'type'     => $Node['type'],
            'objectId' => (int) ($Node['objectId'] ?? 0)
        ];
    }

    /**
     * Hängt die Kinder eines Knotens samt Flüssen an. Kinder unter dem Mindestanteil werden zu "Weitere" zusammengefasst,
     * wenn es mindestens zwei sind. Die Farbe erbt ein Gerät von seiner Gruppe, die obersten Knoten bekommen die Palette.
     */
    private function addChildren(array &$Flow, string $ParentId, array $Children, int $Col, string $ParentColor, float $Total, float $PricePerUnit): void
    {
        $MinShare = $this->ReadPropertyInteger('MinShare');
        $Small = [];
        $Big = [];
        foreach ($Children as $Child) {
            $IsSmall = $MinShare > 0 && $Total > 0 && $Child['value'] / $Total * 100 < $MinShare && $Child['type'] !== 'unrecorded';
            if ($IsSmall) {
                $Small[] = $Child;
            } else {
                $Big[] = $Child;
            }
        }
        if (count($Small) >= 2) {
            $Big[] = ['id' => 'others:' . $ParentId, 'type' => 'others', 'name' => $this->Translate('Others'), 'value' => array_sum(array_column($Small, 'value')), 'children' => []];
        } else {
            $Big = array_merge($Big, $Small);
        }
        foreach ($Big as $Index => $Child) {
            $Color = ($Child['color'] ?? '') !== '' ? $Child['color'] : ($ParentColor !== '' ? $ParentColor : self::PALETTE[$Index % count(self::PALETTE)]);
            if ($Child['type'] === 'unrecorded' || $Child['type'] === 'others' || $Child['type'] === 'rest') {
                $Color = '#94A3B8';
            }
            $this->addNode($Flow, [
                'id'       => $Child['id'],
                'name'     => $Child['name'],
                'value'    => $Child['value'],
                'col'      => $Col,
                'color'    => $Color,
                'type'     => $Child['type'],
                'objectId' => (int) ($Child['objectId'] ?? 0)
            ], $PricePerUnit);
            $Flow['links'][] = ['s' => $ParentId, 't' => $Child['id'], 'v' => round($Child['value'], 3)];
            if (!empty($Child['children'])) {
                $this->addChildren($Flow, $Child['id'], $Child['children'], $Col + 1, $Color, $Total, $PricePerUnit);
            }
        }
    }

    // ---------------------------------------------------------------- Kachel

    private function tileMessage(string $Message): string
    {
        $ID = (int) @$this->GetIDForIdent('Data');
        $Data = $ID > 0 ? json_decode((string) GetValue($ID), true) : null;
        $Request = $this->currentRequest();
        return (string) json_encode([
            'data'         => is_array($Data) ? $Data : null,
            'period'       => $Request['period'],
            'today'        => date('Y-m-d'),
            'message'      => $Message,
            'liveInterval' => $this->ReadPropertyInteger('LiveInterval'),
            'showCost'     => $this->ReadPropertyBoolean('ShowCost'),
            'clickOpen'    => $this->ReadPropertyBoolean('OpenChartOnClick'),
            'texts'        => [
                'today'     => $this->Translate('Today'),
                'week'      => $this->Translate('Week'),
                'month'     => $this->Translate('Month'),
                'year'      => $this->Translate('Year'),
                'custom'    => $this->Translate('Custom'),
                'from'      => $this->Translate('From'),
                'to'        => $this->Translate('To'),
                'show'      => $this->Translate('Show'),
                'live'      => $this->Translate('Live'),
                'liveNote'  => $this->Translate('Current power, counters averaged over the last 30 minutes'),
                'cost'      => $this->Translate('Cost'),
                'share'     => $this->Translate('of the total'),
                'empty'     => $this->Translate('No data yet'),
                'openHint'  => $this->Translate('Click or tap again: open the history')
            ]
        ]);
    }

    private function validate(): string
    {
        if ($this->ReadPropertyInteger('Period') < self::PERIOD_TODAY || $this->ReadPropertyInteger('Period') > self::PERIOD_YEAR) {
            return 'Der Zeitraum ist ungültig';
        }
        if ($this->ReadPropertyInteger('MinShare') < 0 || $this->ReadPropertyInteger('MinShare') > 50) {
            return 'Der Mindestanteil muss zwischen 0 und 50 Prozent liegen';
        }
        if ($this->ReadPropertyInteger('LiveInterval') < 5 || $this->ReadPropertyInteger('LiveInterval') > 300) {
            return 'Das Intervall der Live-Anzeige muss zwischen 5 und 300 Sekunden liegen';
        }
        if ($this->ReadPropertyInteger('UpdateInterval') < 1) {
            return 'Das Aktualisierungsintervall ist ungültig';
        }
        return '';
    }
}
