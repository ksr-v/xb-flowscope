<?php

namespace Plugin\Flowscope\Services;

use InvalidArgumentException;

/** Integer decimal arithmetic keeps bytes exact beyond JavaScript's 2^53. */
final class QuotaService
{
    public const MAX_BYTES = '9223372036854775807';
    public const DEFAULT_BYTES = '1099511627776';

    public function normalizeBytes(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,19}$/D', (string) $value)) {
            throw new InvalidArgumentException('Quota must be a positive integer number of bytes.');
        }
        $bytes = $this->unsigned((string) $value);
        if ($bytes === '0' || $this->compare($bytes, self::MAX_BYTES) > 0) {
            throw new InvalidArgumentException('Quota must be between 1 and '.self::MAX_BYTES.' bytes.');
        }
        return $bytes;
    }

    /** Binary GB/TB aliases match Xboard's 1024-based traffic units. Fractional bytes are truncated. */
    public function fromValue(string $value, string $unit): string
    {
        if (!preg_match('/^[0-9]{1,19}(?:\.[0-9]{1,12})?$/D', $value)) {
            throw new InvalidArgumentException('Quota value must be a decimal with at most 12 fractional digits.');
        }
        $factor = match ($unit) {
            'GB', 'GiB' => '1073741824',
            'TB', 'TiB' => '1099511627776',
            default => throw new InvalidArgumentException('Quota unit must be GB, TB, GiB, or TiB.'),
        };
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $product = $this->multiply($whole.$fraction, $factor);
        $bytes = $fraction === '' ? $product : (strlen($product) > strlen($fraction) ? substr($product, 0, -strlen($fraction)) : '0');
        return $this->normalizeBytes($bytes);
    }

    public function add(string $a, string $b): string
    {
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        $a = $this->unsigned(ltrim($a, '-'));
        $b = $this->unsigned(ltrim($b, '-'));
        if ($negativeA === $negativeB) {
            $result = $this->addUnsigned($a, $b);
            return $negativeA && $result !== '0' ? '-'.$result : $result;
        }
        $comparison = $this->compare($a, $b);
        if ($comparison === 0) {
            return '0';
        }
        $result = $comparison > 0 ? $this->subtractUnsigned($a, $b) : $this->subtractUnsigned($b, $a);
        return ($comparison > 0 ? $negativeA : $negativeB) ? '-'.$result : $result;
    }

    public function nonNegative(string $value): string
    {
        return str_starts_with($value, '-') ? '0' : $this->unsigned($value);
    }

    /** Compare nonnegative integers without float conversion. */
    public function compare(string $a, string $b): int
    {
        $a = $this->unsigned($a);
        $b = $this->unsigned($b);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    public function multiply(string $a, string $b): string
    {
        $a = $this->unsigned($a);
        $b = $this->unsigned($b);
        $digits = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $digits[$position] + (int) $a[$i] * (int) $b[$j];
                $digits[$position] = $sum % 10;
                $digits[$position - 1] += intdiv($sum, 10);
            }
        }
        return $this->unsigned(implode('', $digits));
    }

    /** Floor division by a small positive integer, without converting bytes to float. */
    public function divide(string $value, int $divisor): string
    {
        if ($divisor < 1 || $divisor > 31622400) {
            throw new InvalidArgumentException('Divisor must be between 1 and 366 days in seconds.');
        }
        $value = $this->unsigned($value);
        $quotient = '';
        $remainder = 0;
        foreach (str_split($value) as $digit) {
            $remainder = $remainder * 10 + (int) $digit;
            $quotient .= (string) intdiv($remainder, $divisor);
            $remainder %= $divisor;
        }
        return $this->unsigned($quotient);
    }

    private function unsigned(string $value): string
    {
        if (!preg_match('/^[0-9]+$/D', $value)) {
            throw new InvalidArgumentException('Byte arithmetic requires integer decimal strings.');
        }
        return ltrim($value, '0') ?: '0';
    }

    private function addUnsigned(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }
        return $result;
    }

    /** $a >= $b. */
    private function subtractUnsigned(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $digit = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = ($digit < 0 ? $digit + 10 : $digit).$result;
        }
        return $this->unsigned($result);
    }
}
