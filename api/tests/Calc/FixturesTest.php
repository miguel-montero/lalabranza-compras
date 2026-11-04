<?php
declare(strict_types=1);

namespace KT\Tests\Calc;

use InvalidArgumentException;
use KT\Calc\Allocation;
use KT\Calc\Compare;
use KT\Calc\LineCalc;
use KT\Calc\Units;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    private const DELTA = 1e-4;

    /** @return array<string, array{0: array, 1: mixed}> */
    private static function cases(string $key): array
    {
        $data = json_decode(file_get_contents(__DIR__ . '/../../../shared/calc-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($data[$key] as $i => $c) {
            $out["$key #$i " . json_encode($c['in'])] = [$c['in'], $c['out']];
        }
        return $out;
    }

    private function assertSame2(mixed $expected, mixed $actual): void
    {
        if (is_array($expected)) {
            $this->assertIsArray($actual);
            $this->assertSame(array_keys($expected), array_keys($actual));
            foreach ($expected as $k => $v) {
                $this->assertSame2($v, $actual[$k]);
            }
        } elseif (is_float($expected) || is_int($expected)) {
            $this->assertNotNull($actual);
            $this->assertEqualsWithDelta($expected, $actual, self::DELTA);
        } else {
            $this->assertSame($expected, $actual);
        }
    }

    public static function toBaseCases(): array { return self::cases('toBase'); }
    public static function baseQuantityCases(): array { return self::cases('baseQuantity'); }
    public static function spreadCases(): array { return self::cases('spread'); }
    public static function costsCases(): array { return self::cases('costs'); }
    public static function normalizedPriceCases(): array { return self::cases('normalizedPrice'); }
    public static function receiptTotalsCases(): array { return self::cases('receiptTotals'); }
    public static function changeCases(): array { return self::cases('change'); }

    #[DataProvider('toBaseCases')]
    public function testToBase(array $in, mixed $out): void
    {
        $this->assertSame2($out, Units::toBase(...$in));
    }

    #[DataProvider('baseQuantityCases')]
    public function testBaseQuantity(array $in, mixed $out): void
    {
        $this->assertSame2($out, LineCalc::baseQuantity(...$in));
    }

    #[DataProvider('spreadCases')]
    public function testSpread(array $in, mixed $out): void
    {
        $this->assertSame($out, Allocation::spread(...$in));
    }

    public function testSpreadZeroSumThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Allocation::spread([0, 0], 10);
    }

    #[DataProvider('costsCases')]
    public function testCosts(array $in, mixed $out): void
    {
        $this->assertSame($out, LineCalc::costs(...$in));
    }

    #[DataProvider('normalizedPriceCases')]
    public function testNormalizedPrice(array $in, mixed $out): void
    {
        $this->assertSame2($out, LineCalc::normalizedPrice(...$in));
    }

    #[DataProvider('receiptTotalsCases')]
    public function testReceiptTotals(array $in, mixed $out): void
    {
        $this->assertSame($out, LineCalc::receiptTotals(...$in));
    }

    #[DataProvider('changeCases')]
    public function testChange(array $in, mixed $out): void
    {
        $this->assertSame2($out, Compare::change(...$in));
    }

    public function testEnteredAmount(): void
    {
        $this->assertSame(1234, LineCalc::enteredAmount(['entry_mode' => 'total', 'entered_amount' => 1234]));
        $this->assertSame(2500, LineCalc::enteredAmount(['entry_mode' => 'unit_price', 'unit_price' => 500, 'quantity' => 5]));
        $this->assertSame(1500, LineCalc::enteredAmount(['entry_mode' => 'unit_price', 'unit_price' => 500, 'quantity' => 1, 'package_count' => 3]));
        $this->assertSame(1250, LineCalc::enteredAmount(['entry_mode' => 'unit_price', 'unit_price' => 1000, 'quantity' => 1.25]));
    }
}
