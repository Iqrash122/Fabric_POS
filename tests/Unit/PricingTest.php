<?php

namespace Tests\Unit;

use App\Domain\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    private function line(int $q, int $p, string $type = 'fixed', int $d = 0, array $tax = []): array
    {
        return ['quantity' => $q, 'unit_price' => $p, 'discount_type' => $type, 'discount_value' => $d, 'taxes' => $tax];
    }

    public function test_supplied_discount_examples(): void
    {
        $this->assertSame(270000, Pricing::calculate([$this->line(2, 150000, 'percent', 1000)])['total']);
        $this->assertSame(450000, Pricing::calculate([$this->line(1, 500000, 'fixed', 50000)])['total']);
        $this->assertSame(480000, Pricing::calculate([$this->line(1, 500000, 'fixed', 20000)])['total']);
        $this->assertSame(315000, Pricing::calculate([$this->line(1, 300000, 'fixed', 20000), $this->line(1, 70000)], 'percent', 1000)['total']);
    }

    public function test_exact_deterministic_largest_remainder(): void
    {
        $this->assertSame([1, 1, 0], Pricing::allocate(2, [1, 1, 1]));
        $this->assertSame(17, array_sum(Pricing::allocate(17, [23, 71, 11])));
    }

    public function test_zero_total_and_known_zero(): void
    {
        $this->assertSame(0, Pricing::calculate([$this->line(1, 100)], 'percent', 10000)['total']);
        $this->assertSame(0, Pricing::minor('0'));
    }

    public function test_included_excluded_fixed_and_compound_taxes(): void
    {
        $p = ['name' => 'VAT', 'type' => 'percent', 'rate' => 1000, 'included' => false, 'compound' => false];
        $this->assertSame(11000, Pricing::calculate([$this->line(1, 10000, tax: [$p])])['total']);
        $p['included'] = true;
        $r = Pricing::calculate([$this->line(1, 11000, tax: [$p])]);
        $this->assertSame(10000, $r['net']);
        $this->assertSame(1000, $r['tax']);
        $p['included'] = false;
        $p2 = [...$p, 'name' => 'Second', 'compound' => true];
        $this->assertSame(12100, Pricing::calculate([$this->line(1, 10000, tax: [$p, $p2])])['total']);
        $fixed = ['name' => 'Fixed', 'type' => 'fixed', 'rate' => 50, 'included' => false, 'compound' => false];
        $this->assertSame(2100, Pricing::calculate([$this->line(2, 1000, tax: [$fixed])])['total']);
    }
}
