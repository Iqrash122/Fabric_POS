<?php

namespace App\Domain;

use Illuminate\Validation\ValidationException;

final class Pricing
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }

    public static function minor(mixed $value): int
    {
        if (! preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string) $value)) {
            self::fail('Enter a non-negative amount with at most two decimal places.');
        }

        return (int) bcmul(self::decimal($value), '100', 0);
    }

    /** @return numeric-string */
    public static function decimal(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            self::fail('Enter a decimal number.');
        }
        $number = (string) $value;
        if (! is_numeric($number) || ! preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $number)) {
            self::fail('Enter a decimal number.');
        }

        return $number;
    }

    public static function round(string $value): int
    {
        if (! is_numeric($value)) {
            self::fail('Invalid numeric amount.');
        }
        if (bccomp($value, (string) PHP_INT_MAX, 0) >= 0 || bccomp($value, '0', 16) < 0) {
            self::fail('Calculated amount exceeds the supported range.');
        }

        return (int) bcadd($value, '0.5', 0);
    }

    public static function discount(int $base, string $type, int $value): int
    {
        if ($value < 0 || ! in_array($type, ['fixed', 'percent'])) {
            self::fail('Invalid discount.');
        }
        if ($type === 'percent' && $value > 10000) {
            self::fail('Discount percentage must be 0–100.');
        }
        $d = $type === 'percent' ? self::round(bcdiv(bcmul((string) $base, (string) $value, 0), '10000', 12)) : $value;
        if ($d > $base) {
            self::fail('Discount exceeds its base.');
        }

        return $d;
    }

    /**
     * @param  array<int, int>  $weights
     * @return array<string|int, mixed>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($amount < 0 || $amount > $sum) {
            self::fail('Invalid allocation.');
        }
        if (! $sum) {
            return array_fill(0, count($weights), 0);
        }
        $shares = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            $numerator = bcmul((string) $amount, (string) $w, 0);
            $shares[$i] = (int) bcdiv($numerator, (string) $sum, 0);
            $remainders[$i] = (int) bcmod($numerator, (string) $sum);
        }
        $left = $amount - array_sum($shares);
        uksort($remainders, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        foreach (array_keys($remainders) as $i) {
            if ($left-- <= 0) {
                break;
            }$shares[$i]++;
        }ksort($shares);

        return $shares;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string|int, mixed>
     */
    public static function calculate(array $lines, string $type = 'fixed', int $discount = 0): array
    {
        $bases = [];
        $gross = [];
        $lineDiscounts = [];
        foreach ($lines as $i => $l) {
            if ($l['quantity'] < 1 || $l['quantity'] > 10000 || $l['unit_price'] < 0 || $l['unit_price'] > 99999999999) {
                self::fail('Invalid quantity or unit price.');
            }
            $gross[$i] = $l['quantity'] * $l['unit_price'];
            $lineDiscounts[$i] = self::discount($gross[$i], $l['discount_type'], $l['discount_value']);
            $bases[$i] = $gross[$i] - $lineDiscounts[$i];
        }
        $header = self::discount(array_sum($bases), $type, $discount);
        $shares = self::allocate($header, $bases);
        $out = [];
        foreach ($lines as $i => $l) {
            $price = $bases[$i] - $shares[$i];
            $a = '1';
            $b = '0';
            $includedA = '0';
            $includedB = '0';
            $terms = [];
            foreach (($price === 0 ? [] : ($l['taxes'] ?? [])) as $tax) {
                $rate = (int) ($tax['rate'] ?? 0);
                if ($rate < 0 || $rate > 10000000 || ! in_array($tax['type'] ?? '', ['percent', 'fixed'])) {
                    self::fail('Invalid tax definition.');
                }
                $r = bcdiv((string) $rate, '10000', 16);
                $ta = $tax['type'] === 'percent' ? bcmul(! empty($tax['compound']) ? $a : '1', $r, 16) : '0';
                $tb = $tax['type'] === 'percent' ? bcmul(! empty($tax['compound']) ? $b : '0', $r, 16) : (string) ($rate * $l['quantity']);
                $terms[] = [$ta, $tb, $tax];
                if (! empty($tax['included'])) {
                    $includedA = bcadd($includedA, $ta, 16);
                    $includedB = bcadd($includedB, $tb, 16);
                }
                $a = bcadd($a, $ta, 16);
                $b = bcadd($b, $tb, 16);
            }
            if (bccomp((string) $price, $includedB, 16) < 0) {
                self::fail('Discounted price is below included fixed taxes.');
            }
            $raw = bcdiv(bcsub((string) $price, $includedB, 16), bcadd('1', $includedA, 16), 16);
            $taxes = [];
            $included = 0;
            $excluded = 0;
            foreach ($terms as [$ta,$tb,$tax]) {
                $amount = self::round(bcadd(bcmul($ta, $raw, 16), $tb, 16));
                $taxes[] = [...$tax, 'amount' => $amount];
                if (! empty($tax['included'])) {
                    $included += $amount;
                } else {
                    $excluded += $amount;
                }
            }
            $net = $price - $included;
            $out[] = ['gross' => $gross[$i], 'line_discount' => $lineDiscounts[$i], 'header_discount' => $shares[$i], 'net' => $net, 'tax' => $included + $excluded, 'total' => $price + $excluded, 'taxes' => $taxes];
        }

        return ['lines' => $out, 'gross' => array_sum($gross), 'discount' => array_sum($lineDiscounts) + $header, 'net' => array_sum(array_column($out, 'net')), 'tax' => array_sum(array_column($out, 'tax')), 'total' => array_sum(array_column($out, 'total'))];
    }
}
