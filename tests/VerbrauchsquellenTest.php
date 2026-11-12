<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class VerbrauchsquellenTest extends TestCase
{
    private const MODULE_ID = '{F2C73767-01DB-45FA-ACBB-4F4DD79C83CC}';

    protected function setUp(): void
    {
        // Jeder Test startet mit einem leeren Kernel und lädt die Bibliothek neu
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setUp();
    }

    public function testEmptyInstanceIsInactive(): void
    {
        $id = $this->createInstance([]);

        $this->assertSame(104, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testElectricityConsumersMakeInstanceActive(): void
    {
        $main = $this->variable();
        $id = $this->createInstance(['Consumers' => $this->consumers([[$main, 'MainMeter']])]);

        $this->assertSame(102, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testUnitMustMatchMedium(): void
    {
        $main = $this->variable();
        // Wasser in kWh und Strom in m³ passen nicht zum Medium
        $water = $this->createInstance(['Unit2' => 'kWh', 'Consumers2' => $this->consumers([[$main, 'MainMeter']])]);
        $electric = $this->createInstance(['Unit' => 'm³', 'Consumers' => $this->consumers([[$main, 'MainMeter']])]);

        $this->assertSame(201, IPS_GetInstance($water)['InstanceStatus']);
        $this->assertSame(201, IPS_GetInstance($electric)['InstanceStatus']);
    }

    public function testDuplicateVariableAndSecondMainMeterAreInvalid(): void
    {
        $a = $this->variable();
        $b = $this->variable();
        $duplicate = $this->createInstance(['Consumers' => $this->consumers([[$a, 'Consumer'], [$a, 'Consumer']])]);
        $twoMeters = $this->createInstance(['Consumers' => $this->consumers([[$a, 'MainMeter'], [$b, 'MainMeter']])]);

        $this->assertSame(201, IPS_GetInstance($duplicate)['InstanceStatus']);
        $this->assertSame(201, IPS_GetInstance($twoMeters)['InstanceStatus']);
    }

    public function testNegativeEmissionFactorIsInvalid(): void
    {
        $main = $this->variable();
        $id = $this->createInstance(['EmissionFactor' => -1.0, 'Consumers' => $this->consumers([[$main, 'MainMeter']])]);

        $this->assertSame(201, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testConfigIsDeliveredPerMedium(): void
    {
        $power = $this->variable();
        $water = $this->variable();
        $id = $this->createInstance([
            'PricePerUnit'     => 0.31,
            'EmissionFactor'   => 400.0,
            'Consumers'        => $this->consumers([[$power, 'MainMeter']]),
            'PricePerUnit2'    => 4.5,
            'Consumers2'       => $this->consumers([[$water, 'MainMeter']]),
            'Residents'        => 3,
            'Area'             => 120.0,
            'HotWaterElectric' => true
        ]);

        $electric = $this->config($id, 1);
        $volume = $this->config($id, 2);

        $this->assertSame($power, $electric['MainMeter']);
        $this->assertSame('energy', $electric['Unit']['Family']);
        $this->assertEqualsWithDelta(0.31, $electric['FixedPrice'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $electric['EmissionFactor'], 0.0001);
        $this->assertSame($water, $volume['MainMeter']);
        $this->assertSame('m³', $volume['Unit']['Out']);
        $this->assertEqualsWithDelta(4.5, $volume['FixedPrice'], 0.0001);
        // Bewohner, Fläche und Warmwasser gelten für alle Medien
        foreach ([$electric, $volume] as $config) {
            $this->assertSame(3, $config['Residents']);
            $this->assertEqualsWithDelta(120.0, $config['Area'], 0.0001);
            $this->assertTrue($config['HotWaterElectric']);
        }
    }

    public function testUnknownMediumFallsBackToElectricity(): void
    {
        $power = $this->variable();
        $id = $this->createInstance(['Consumers' => $this->consumers([[$power, 'MainMeter']])]);

        $this->assertSame(1, $this->config($id, 9)['Medium']);
    }

    public function testHeatingMediumUsesHeatingVariableAndCarrierFactor(): void
    {
        $heat = $this->variable();
        // Erdgas (Träger 1) in kWh: Faktor 201 g/kWh vom Energieträger, Preis je Einheit aus dem Block
        $id = $this->createInstance(['HeatingVariableID' => $heat, 'HeatingCarrier' => 1, 'HeatingPrice' => 0.12]);

        $heating = $this->config($id, 4);

        $this->assertSame($heat, $heating['MainMeter']);
        $this->assertSame([], $heating['Consumers']);
        $this->assertEqualsWithDelta(201.0, $heating['EmissionFactor'], 0.0001);
        $this->assertEqualsWithDelta(0.12, $heating['FixedPrice'], 0.0001);
        $this->assertSame('gas', $heating['Heating']['Carrier']);
    }

    public function testHeatingOwnFactorWinsAndWithoutHeatingNothingIsDelivered(): void
    {
        $heat = $this->variable();
        $own = $this->createInstance(['HeatingVariableID' => $heat, 'HeatingCarrier' => 4, 'HeatingEmissionFactor' => 36.0]);
        $none = $this->createInstance([]);

        $this->assertEqualsWithDelta(36.0, $this->config($own, 4)['EmissionFactor'], 0.0001);
        $this->assertSame(0, $this->config($none, 4)['MainMeter']);
        $this->assertNull($this->config($none, 4)['Heating']);
    }

    public function testHeatingMediumKeepsOtherMediaUntouched(): void
    {
        $power = $this->variable();
        $heat = $this->variable();
        $id = $this->createInstance(['Consumers' => $this->consumers([[$power, 'MainMeter']]), 'HeatingVariableID' => $heat, 'HeatingCarrier' => 1]);

        $this->assertSame($power, $this->config($id, 1)['MainMeter']);
        $this->assertSame($heat, $this->config($id, 4)['MainMeter']);
    }

    public function testCurrencyIsEuroByDefault(): void
    {
        $id = $this->createInstance(['Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertSame('€', $this->config($id, 1)['Currency']);
        $this->assertSame('EUR', $this->config($id, 1)['CurrencyCode']);
        $this->assertSame('€', $this->config($id, 4)['Currency']);
    }

    public function testCurrencyFollowsTheSelection(): void
    {
        $main = $this->variable();
        $swiss = $this->createInstance(['Currency' => 'CHF', 'Consumers' => $this->consumers([[$main, 'MainMeter']])]);
        $pound = $this->createInstance(['Currency' => 'GBP', 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);
        $custom = $this->createInstance(['Currency' => 'CUSTOM', 'CurrencyCustom' => 'Fr', 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertSame('CHF', $this->config($swiss, 1)['Currency']);
        $this->assertSame('£', $this->config($pound, 1)['Currency']);
        $this->assertSame('Fr', $this->config($custom, 1)['Currency']);
    }

    public function testCustomCurrencyWithoutSymbolIsInvalid(): void
    {
        $id = $this->createInstance(['Currency' => 'CUSTOM', 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertSame(201, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testCurrencyIsTakenFromPriceVariableOnlyWithTheCheckbox(): void
    {
        $price = $this->priceVariable(' CHF/kWh', 0.25);
        $main = $this->variable();
        $off = $this->createInstance(['Currency' => 'EUR', 'PriceVariableID' => $price, 'Consumers' => $this->consumers([[$main, 'MainMeter']])]);
        $on = $this->createInstance(['Currency' => 'EUR', 'CurrencyFromPrice' => true, 'PriceVariableID' => $price, 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertSame('€', $this->config($off, 1)['Currency']);
        $this->assertSame('CHF', $this->config($on, 1)['Currency']);
    }

    public function testUnclearSuffixFallsBackToTheSelection(): void
    {
        // "$" und "kr" gehören zu mehreren Ländern, dann gilt die Auswahl und es gibt keinen Fehler
        $id = $this->createInstance(['Currency' => 'GBP', 'CurrencyFromPrice' => true, 'PriceVariableID' => $this->priceVariable(' $/kWh', 0.3), 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertSame(102, IPS_GetInstance($id)['InstanceStatus']);
        $this->assertSame('£', $this->config($id, 1)['Currency']);
    }

    public function testDifferentCurrenciesInPriceVariablesAreInvalid(): void
    {
        $id = $this->createInstance([
            'PriceVariableID'  => $this->priceVariable(' €/kWh', 0.3),
            'Consumers'        => $this->consumers([[$this->variable(), 'MainMeter']]),
            'PriceVariableID2' => $this->priceVariable(' CHF/m³', 4.0),
            'Consumers2'       => $this->consumers([[$this->variable(), 'MainMeter']])
        ]);

        $this->assertSame(201, IPS_GetInstance($id)['InstanceStatus']);
    }

    public function testSameCurrencyWithDifferentFactorsIsValid(): void
    {
        // Strom in ct/kWh, Gas in €/kWh: derselbe Euro, nur verschiedene Faktoren
        $id = $this->createInstance([
            'PriceVariableID'  => $this->priceVariable(' ct/kWh', 28.5),
            'Consumers'        => $this->consumers([[$this->variable(), 'MainMeter']]),
            'Unit3'            => 'kWh',
            'PriceVariableID3' => $this->priceVariable(' €/kWh', 0.12),
            'Consumers3'       => $this->consumers([[$this->variable(), 'MainMeter']])
        ]);

        $this->assertSame(102, IPS_GetInstance($id)['InstanceStatus']);
        $this->assertEqualsWithDelta(0.285, $this->config($id, 1)['PriceNow'], 0.0001);
        $this->assertEqualsWithDelta(0.12, $this->config($id, 3)['PriceNow'], 0.0001);
    }

    public function testPriceFactorModeOverridesTheDetection(): void
    {
        $unclear = $this->priceVariable('', 28.5);
        $auto = $this->createInstance(['PriceVariableID' => $unclear, 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);
        $main = $this->createInstance(['PriceFactorMode' => 1, 'PriceVariableID' => $unclear, 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);
        $sub = $this->createInstance(['PriceFactorMode' => 2, 'PriceVariableID' => $unclear, 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

        $this->assertEqualsWithDelta(28.5, $this->config($auto, 1)['PriceNow'], 0.0001);
        $this->assertEqualsWithDelta(28.5, $this->config($main, 1)['PriceNow'], 0.0001);
        $this->assertEqualsWithDelta(0.285, $this->config($sub, 1)['PriceNow'], 0.0001);
        $this->assertSame(2, $this->config($sub, 1)['PriceFactorMode']);
    }

    public function testSubunitsOfOtherCurrenciesAreDetected(): void
    {
        foreach ([' p/kWh' => 'GBP', ' Rp./kWh' => 'CHF', ' ct/kWh' => 'EUR'] as $suffix => $code) {
            $id = $this->createInstance(['Currency' => 'USD', 'CurrencyFromPrice' => true, 'PriceVariableID' => $this->priceVariable($suffix, 20.0), 'Consumers' => $this->consumers([[$this->variable(), 'MainMeter']])]);

            $this->assertSame($code, $this->config($id, 1)['CurrencyCode'], $suffix);
            $this->assertEqualsWithDelta(0.2, $this->config($id, 1)['PriceNow'], 0.0001, $suffix);
        }
    }

    private function priceVariable(string $suffix, float $value): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        IPS_SetVariableCustomPresentation($id, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix]);
        SetValue($id, $value);
        return $id;
    }

    private function createInstance(array $configuration): int
    {
        $id = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetConfiguration($id, json_encode((object) $configuration));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function variable(): int
    {
        return IPS_CreateVariable(VARIABLETYPE_FLOAT);
    }

    /**
     * @param array<int, array{0: int, 1: string}> $rows Variable und Rolle
     */
    private function consumers(array $rows): string
    {
        return json_encode(array_map(fn (array $Row): array => ['VariableID' => $Row[0], 'Role' => $Row[1], 'Group' => '', 'DisplayName' => ''], $rows));
    }

    private function config(int $instanceID, int $medium): array
    {
        $answer = IPS\InstanceManager::getInstanceInterface($instanceID)->ForwardData(json_encode(['Action' => 'GetConfig', 'Medium' => $medium]));
        return json_decode($answer, true);
    }
}
