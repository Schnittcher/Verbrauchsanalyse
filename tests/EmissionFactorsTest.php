<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

include_once __DIR__ . '/../libs/EmissionFactorsStrict.php';

class EmissionFactorsTest extends TestCase
{
    public function testCarrierFactorsAreUsedWithoutOwnValue(): void
    {
        $this->assertEqualsWithDelta(201.0, $this->factor(['Carrier' => 'gas']), 0.0001);
        $this->assertEqualsWithDelta(266.0, $this->factor(['Carrier' => 'oil']), 0.0001);
        $this->assertEqualsWithDelta(180.0, $this->factor(['Carrier' => 'district']), 0.0001);
    }

    public function testOwnValueWinsOverCarrier(): void
    {
        $this->assertEqualsWithDelta(36.0, $this->factor(['Carrier' => 'pellets', 'EmissionFactor' => 36.0]), 0.0001);
        $this->assertEqualsWithDelta(150.0, $this->factor(['Carrier' => 'gas', 'EmissionFactor' => 150.0]), 0.0001);
    }

    public function testNoFactorForElectricityPelletsAndOther(): void
    {
        // Strom zählt beim Strom, Pellets und Sonstiges brauchen einen eigenen Wert
        $this->assertSame(0.0, $this->factor(['Carrier' => 'electric', 'EmissionFactor' => 380.0]));
        $this->assertSame(0.0, $this->factor(['Carrier' => 'pellets']));
        $this->assertSame(0.0, $this->factor(['Carrier' => 'other']));
        $this->assertSame(0.0, $this->factor([]));
    }

    public function testEnergyPerUnitFollowsUnitFamily(): void
    {
        $energy = ['Family' => 'energy'];
        $volume = ['Family' => 'volume'];

        $this->assertSame(1.0, $this->perUnit(['Carrier' => 'gas'], $energy));
        $this->assertEqualsWithDelta(4.8, $this->perUnit(['Carrier' => 'pellets', 'EnergyPerUnit' => 4.8], ['Family' => 'custom:kg']), 0.0001);
        // Ohne Heizwert gelten bei Erdgas und Heizöl etwa 10 kWh je Einheit, bei Pellets gibt es keinen Ersatz
        $this->assertEqualsWithDelta(10.0, $this->perUnit(['Carrier' => 'gas'], $volume), 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->perUnit(['Carrier' => 'oil'], $volume), 0.0001);
        $this->assertNull($this->perUnit(['Carrier' => 'pellets'], ['Family' => 'custom:kg']));
        $this->assertEqualsWithDelta(11.2, $this->perUnit(['Carrier' => 'gas', 'EnergyPerUnit' => 11.2], $volume), 0.0001);
    }

    private function factor(array $heating): float
    {
        return $this->subject()->factorOf($heating);
    }

    private function perUnit(array $heating, array $unit): ?float
    {
        return $this->subject()->perUnitOf($heating, $unit);
    }

    private function subject(): object
    {
        return new class() {
            use EmissionFactors;

            public function factorOf(array $Heating): float
            {
                return $this->heatingFactor($Heating);
            }

            public function perUnitOf(array $Heating, array $Info): ?float
            {
                return $this->heatingEnergyPerUnit($Heating, $Info);
            }
        };
    }
}
