<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class VerbrauchsberichtTest extends TestCase
{
    private const MODULE_ID = '{6AAFDE08-2263-4B3F-AD76-165DF9798882}';

    protected function setUp(): void
    {
        // Jeder Test startet mit einem leeren Kernel und lädt die Bibliothek neu
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
    }

    public function testNewInstanceHasOneDefaultReport(): void
    {
        $id = $this->createInstance([]);

        $reports = $this->call($id, 'reports');

        $this->assertSame(['Report'], array_keys($reports));
        $this->assertSame(1, $reports['Report']['Medium']);
        $this->assertSame('', $this->call($id, 'validate'));
    }

    public function testListDecidesMediumOfReport(): void
    {
        $id = $this->createInstance([
            'Reports'  => json_encode([$this->row('Strom täglich')]),
            'Reports2' => json_encode([$this->row('Wasser wöchentlich', ['Period' => 1])]),
            'Reports3' => json_encode([$this->row('Gas monatlich', ['Period' => 2])]),
            'Reports4' => json_encode([$this->row('Heizung monatlich', ['Period' => 2])])
        ]);

        $reports = $this->call($id, 'reports');

        $this->assertSame(
            ['Strom täglich' => 1, 'Wasser wöchentlich' => 2, 'Gas monatlich' => 3, 'Heizung monatlich' => 4],
            array_map(fn (array $Report): int => $Report['Medium'], $reports)
        );
        $this->assertSame('', $this->call($id, 'validate'));
    }

    public function testNamesMustBeUniqueAcrossAllLists(): void
    {
        $id = $this->createInstance([
            'Reports'  => json_encode([$this->row('Bericht')]),
            'Reports2' => json_encode([$this->row('Bericht')])
        ]);

        $this->assertStringContainsString('doppelt', $this->call($id, 'validate'));
    }

    public function testEmptyNameAndEmptyListsAreInvalid(): void
    {
        $emptyName = $this->createInstance(['Reports' => json_encode([$this->row('  ')])]);
        $nothing = $this->createInstance(['Reports' => '[]']);

        $this->assertStringContainsString('keinen Namen', $this->call($emptyName, 'validate'));
        $this->assertStringContainsString('leer', $this->call($nothing, 'validate'));
    }

    public function testScheduleValuesAreChecked(): void
    {
        $badHour = $this->createInstance(['Reports' => json_encode([$this->row('A', ['Hour' => 24])])]);
        $badWeekday = $this->createInstance(['Reports' => json_encode([$this->row('B', ['Period' => 1, 'Weekday' => 8])])]);
        $badMonthDay = $this->createInstance(['Reports' => json_encode([$this->row('C', ['Period' => 2, 'MonthDay' => 29])])]);
        // Wochentag und Tag im Monat zählen nur bei passendem Zeitraum
        $ignored = $this->createInstance(['Reports' => json_encode([$this->row('D', ['Period' => 0, 'Weekday' => 99, 'MonthDay' => 99])])]);

        $this->assertStringContainsString('Berichtszeit', $this->call($badHour, 'validate'));
        $this->assertStringContainsString('Wochentag', $this->call($badWeekday, 'validate'));
        $this->assertStringContainsString('Tag im Monat', $this->call($badMonthDay, 'validate'));
        $this->assertSame('', $this->call($ignored, 'validate'));
    }

    public function testMailNeedsSmtpInstanceAndValidRecipients(): void
    {
        $noSmtp = $this->createInstance(['Reports' => json_encode([$this->row('Mail', ['Mail' => true])])]);
        $badRecipient = $this->createInstance(['Reports' => json_encode([$this->row('Mail', ['Mail' => true, 'Recipient' => 'kein-empfaenger'])])]);

        $this->assertStringContainsString('SMTP', $this->call($noSmtp, 'validate'));
        $this->assertStringContainsString('Empfängeradresse', $this->call($badRecipient, 'validate'));
    }

    public function testDeviceListSettingsAreChecked(): void
    {
        $badTop = $this->createInstance(['Reports' => json_encode([$this->row('Top', ['ListMode' => 2, 'TopN' => 0])])]);
        $badMode = $this->createInstance(['Reports' => json_encode([$this->row('Liste', ['ListMode' => 3])])]);

        $this->assertStringContainsString('Geräteliste', $this->call($badTop, 'validate'));
        $this->assertStringContainsString('Geräteliste', $this->call($badMode, 'validate'));
    }

    public function testMediaIdentDependsOnNameAndKind(): void
    {
        $id = $this->createInstance([]);

        $pdf = $this->call($id, 'mediaIdent', ['Strom monatlich', 'PDF']);
        $csv = $this->call($id, 'mediaIdent', ['Strom monatlich', 'CSV']);

        $this->assertSame($pdf, $this->call($id, 'mediaIdent', ['Strom monatlich', 'PDF']));
        $this->assertNotSame($pdf, $csv);
        $this->assertNotSame($pdf, $this->call($id, 'mediaIdent', ['Wasser monatlich', 'PDF']));
        $this->assertMatchesRegularExpression('/^Media_[0-9a-f]{8}_PDF$/', $pdf);
    }

    public function testInstanceWithoutSplitterHasNoSourcesStatus(): void
    {
        $id = $this->createInstance([]);

        $this->assertSame(203, IPS_GetInstance($id)['InstanceStatus']);
    }

    private function createInstance(array $configuration): int
    {
        $id = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetConfiguration($id, json_encode((object) $configuration));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function row(string $name, array $values = []): array
    {
        return array_merge(['Name' => $name, 'Period' => 0, 'Auto' => false, 'Hour' => 7, 'Minute' => 0, 'Weekday' => 1, 'MonthDay' => 1, 'Mail' => false, 'Recipient' => '', 'Subject' => '', 'PDF' => false, 'CSV' => true, 'ListMode' => 1, 'TopN' => 5], $values);
    }

    /**
     * Ruft eine private Methode der Instanz auf, die Berichtsliste und ihre Prüfung sind sonst nur über die Kachel zu erreichen.
     */
    private function call(int $instanceID, string $method, array $arguments = []): mixed
    {
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $reflection = new ReflectionMethod($module, $method);
        return $reflection->invoke($module, ...$arguments);
    }
}
