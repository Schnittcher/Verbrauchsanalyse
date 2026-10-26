<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HeizspiegelTest extends TestCase
{
    private const MODULE_ID = '{41F4E0E2-C002-4AE0-9A02-75FD400C09F5}';

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
    }

    public function testNewInstanceHasDefaultSurcharge(): void
    {
        $id = $this->createInstance([]);

        $this->assertEqualsWithDelta(24.0, $this->surcharge($id, 2025)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(9.6, $this->surcharge($id, 2025)['KWhHeatPump'], 0.0001);
    }

    public function testSurchargeFollowsTheYearOfTheEdition(): void
    {
        $id = $this->createInstance([
            'Surcharges' => json_encode([
                $this->row(2025, 24.0, 2.65),
                $this->row(2027, 30.0, 3.10)
            ])
        ]);

        // Ein Jahr nimmt seine Zeile, ein späteres die letzte frühere, ein früheres die früheste
        $this->assertEqualsWithDelta(24.0, $this->surcharge($id, 2025)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(24.0, $this->surcharge($id, 2026)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->surcharge($id, 2027)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->surcharge($id, 2030)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(24.0, $this->surcharge($id, 2020)['KWh'], 0.0001);
    }

    public function testEmptyListFallsBackToDefault(): void
    {
        $id = $this->createInstance(['Surcharges' => '[]']);

        $this->assertEqualsWithDelta(2.65, $this->surcharge($id, 2025)['Cost'], 0.0001);
    }

    public function testEarlierSingleValuesAreMovedIntoTheListOnce(): void
    {
        // Eine Instanz aus der Zeit vor der Liste: eigene Einzelwerte, die Liste noch mit der Vorgabe und keine Marke
        $id = $this->createInstance([]);
        IPS_SetConfiguration($id, json_encode((object) ['HotWaterKWh' => 20.0, 'HotWaterCost' => 2.0]));
        $this->writeMarker($id, false);
        IPS_ApplyChanges($id);

        $this->assertEqualsWithDelta(20.0, $this->surcharge($id, 2025)['KWh'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $this->surcharge($id, 2025)['Cost'], 0.0001);
        $this->assertEqualsWithDelta(9.6, $this->surcharge($id, 2025)['KWhHeatPump'], 0.0001);

        // Eine zweite Übernahme ändert nichts, auch nicht an neu eingetragenen Werten
        IPS_SetConfiguration($id, json_encode((object) ['Surcharges' => json_encode([$this->row(2025, 25.0, 2.7)])]));
        IPS_ApplyChanges($id);
        $this->assertEqualsWithDelta(25.0, $this->surcharge($id, 2025)['KWh'], 0.0001);
    }

    public function testOwnListIsNotReplacedByEarlierSingleValues(): void
    {
        $id = $this->createInstance([]);
        IPS_SetConfiguration($id, json_encode((object) ['HotWaterKWh' => 20.0, 'Surcharges' => json_encode([$this->row(2026, 31.0, 3.0)])]));
        $this->writeMarker($id, false);
        IPS_ApplyChanges($id);

        $this->assertEqualsWithDelta(31.0, $this->surcharge($id, 2026)['KWh'], 0.0001);
    }

    public function testRowOnTopLevelWithValuesCountsAsRowOfItsYear(): void
    {
        $group = ['id' => 1, 'Year' => 2026, 'Area' => 0, 'Carrier' => 0, 'KWh1' => 0, 'KWh2' => 0, 'KWh3' => 0, 'Cost1' => 0, 'Cost2' => 0, 'Cost3' => 0];
        $own = ['id' => 2, 'Year' => 2026, 'Area' => 80, 'Carrier' => 1, 'KWh1' => 50, 'KWh2' => 100, 'KWh3' => 150, 'Cost1' => 5, 'Cost2' => 10, 'Cost3' => 15];

        // Eine leere Gruppe ergibt keine Tabelle, eine Zeile mit Werten auf der obersten Ebene schon
        $this->assertSame([], $this->tables($this->createInstance(['Tables' => json_encode([$group])])));
        $tables = $this->tables($this->createInstance(['Tables' => json_encode([$own])]));
        $this->assertSame([2026], array_keys($tables));
        $this->assertSame([50.0, 100.0, 150.0], $tables[2026][80]['gas']['kwh']);
    }

    public function testRowsWithoutRisingLimitsAreIgnored(): void
    {
        $row = ['id' => 1, 'Year' => 2026, 'Area' => 80, 'Carrier' => 1, 'KWh1' => 150, 'KWh2' => 100, 'KWh3' => 50, 'Cost1' => 0, 'Cost2' => 0, 'Cost3' => 0];

        $this->assertSame([], $this->tables($this->createInstance(['Tables' => json_encode([$row])])));
    }

    private function row(int $year, float $kwh, float $cost): array
    {
        return ['Year' => $year, 'KWh' => $kwh, 'Cost' => $cost, 'KWhHeatPump' => 9.6, 'CostHeatPump' => 2.45];
    }

    private function createInstance(array $configuration): int
    {
        $id = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetConfiguration($id, json_encode((object) $configuration));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function writeMarker(int $instanceID, bool $value): void
    {
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        (new ReflectionMethod($module, 'WriteAttributeBoolean'))->invoke($module, 'SurchargesMigrated', $value);
    }

    private function tables(int $instanceID): array
    {
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        return (new ReflectionMethod($module, 'tables'))->invoke($module);
    }

    private function surcharge(int $instanceID, int $year): array
    {
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        return (new ReflectionMethod($module, 'surchargeFor'))->invoke($module, $year);
    }
}
