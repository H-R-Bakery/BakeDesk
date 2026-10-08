<?php

declare(strict_types=1);

namespace App\Application\Production;

final class DecimalArithmetic
{
    public function isPositive(string $value): bool
    {
        [$digits] = $this->parse($value);

        return '0' !== $digits;
    }

    public function multiply(string $left, string $right): string
    {
        [$leftDigits, $leftScale] = $this->parse($left);
        [$rightDigits, $rightScale] = $this->parse($right);

        return $this->format(
            $this->multiplyIntegers($leftDigits, $rightDigits),
            $leftScale + $rightScale,
        );
    }

    public function add(string $left, string $right): string
    {
        [$leftDigits, $leftScale] = $this->parse($left);
        [$rightDigits, $rightScale] = $this->parse($right);
        $scale = max($leftScale, $rightScale);
        $leftDigits .= str_repeat('0', $scale - $leftScale);
        $rightDigits .= str_repeat('0', $scale - $rightScale);

        return $this->format($this->addIntegers($leftDigits, $rightDigits), $scale);
    }

    /** @return array{0: string, 1: int} */
    private function parse(string $value): array
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid decimal.', $value));
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $digits = ltrim($whole.$fraction, '0');

        return ['' === $digits ? '0' : $digits, strlen($fraction)];
    }

    private function format(string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0');
        if ('' === $digits) {
            return '0';
        }

        if (0 === $scale) {
            return $digits;
        }

        if (strlen($digits) <= $scale) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        }

        $whole = ltrim(substr($digits, 0, -$scale), '0');
        $fraction = rtrim(substr($digits, -$scale), '0');

        if ('' === $fraction) {
            return '' === $whole ? '0' : $whole;
        }

        return sprintf('%s.%s', '' === $whole ? '0' : $whole, $fraction);
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        if ('0' === $left || '0' === $right) {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; --$leftIndex) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; --$rightIndex) {
                $result[$leftIndex + $rightIndex + 1] += (int) $left[$leftIndex] * (int) $right[$rightIndex];
            }
        }

        for ($index = count($result) - 1; $index > 0; --$index) {
            $carry = intdiv($result[$index], 10);
            $result[$index] %= 10;
            $result[$index - 1] += $carry;
        }

        return ltrim(implode('', $result), '0');
    }

    private function addIntegers(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += (int) $left[$leftIndex--];
            }
            if ($rightIndex >= 0) {
                $sum += (int) $right[$rightIndex--];
            }

            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }
}
