<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;
use OverflowException;

/**
 * Integer-money value object.
 *
 * All arithmetic happens in kobo (1 naira = 100 kobo) as 64-bit integers. Floats are never
 * used for money, which removes an entire class of rounding defects from the platform.
 * DECIMAL(15,2) allows 13 integer digits; MAX_CENTS is the largest value this class holds.
 */
final class Money
{
    public const MAX_CENTS = 999999999999999; // 9,999,999,999,999.99

    private function __construct(private readonly int $cents)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
        if ($cents > self::MAX_CENTS) {
            throw new OverflowException('Amount exceeds the supported range.');
        }
        return new self($cents);
    }

    /** Parse a human-entered naira value such as "1000", "1000.5" or "1,000.50". */
    public static function fromNaira(mixed $value): self
    {
        $raw = trim((string) $value);
        $raw = str_replace([',', ' ', '₦'], '', $raw);
        if ($raw === '') {
            throw new InvalidArgumentException('Enter an amount.');
        }
        if (!preg_match('/^(\d{1,13})(?:\.(\d{0,2}))?$/D', $raw, $m)) {
            throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
        }
        $fraction = str_pad($m[2] ?? '', 2, '0');
        $cents    = ((int) $m[1]) * 100 + (int) $fraction;
        if (!is_int($cents)) {
            // 32-bit PHP silently overflows to float here; refuse rather than lose kobo.
            throw new OverflowException('This server build uses 32-bit integers; RISE 63 requires 64-bit PHP.');
        }
        return self::fromCents($cents);
    }

    /** Read a DECIMAL(15,2) column without going through a float. */
    public static function fromDecimal(mixed $value): self
    {
        return self::fromNaira($value === null || $value === '' ? '0' : (string) $value);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function plus(self $other): self
    {
        $sum = $this->cents + $other->cents;
        return self::fromCents($sum);
    }

    public function minus(self $other): self
    {
        if ($other->cents > $this->cents) {
            throw new InvalidArgumentException('Subtraction would produce a negative amount.');
        }
        return new self($this->cents - $other->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function compare(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * Apply a rate expressed in basis points with half-up rounding.
     * 1000 bps = 10 %. Used for program allocation percentages.
     */
    public function percent(int $basisPoints, int $scale = 10000): self
    {
        if ($basisPoints < 0 || $basisPoints > $scale) {
            throw new InvalidArgumentException('Rate out of range.');
        }
        $product = $this->cents * $basisPoints;
        $rounded = intdiv($product + intdiv($scale, 2), $scale);
        return self::fromCents($rounded);
    }

    /**
     * Split an amount by integer weights so the parts always sum back to the total.
     * Remainder kobo are handed out one at a time in weight order (deterministic).
     *
     * @param list<int> $weights
     * @return list<self>
     */
    public static function allocate(self $total, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('At least one weight is required.');
        }
        $sum = 0;
        foreach ($weights as $weight) {
            if (!is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException('Weights must be non-negative integers.');
            }
            $sum += $weight;
        }
        if ($sum === 0) {
            throw new InvalidArgumentException('Weights cannot all be zero.');
        }
        $parts      = [];
        $distributed = 0;
        foreach ($weights as $weight) {
            $part    = intdiv($total->cents * $weight, $sum);
            $parts[] = $part;
            $distributed += $part;
        }
        $remainder = $total->cents - $distributed;
        $index     = 0;
        $count     = count($weights);
        while ($remainder > 0) {
            $parts[$index % $count] += 1;
            $remainder--;
            $index++;
        }
        return array_map(static fn (int $cents): self => self::fromCents($cents), $parts);
    }

    /** Plain DECIMAL string for the database, e.g. "100000.00". */
    public function toDecimal(): string
    {
        return intdiv($this->cents, 100) . '.' . str_pad((string) ($this->cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "₦100,000.00" for display. */
    public function format(): string
    {
        $naira = intdiv($this->cents, 100);
        $kobo  = str_pad((string) ($this->cents % 100), 2, '0', STR_PAD_LEFT);
        return '₦' . number_format($naira) . '.' . $kobo;
    }

    /** Compact label for tight mobile cards: "₦100k", "₦1.2m". */
    public function short(): string
    {
        $naira = intdiv($this->cents, 100);
        if ($naira >= 1000000) {
            return '₦' . rtrim(rtrim(number_format($naira / 1000000, 1, '.', ''), '0'), '.') . 'm';
        }
        if ($naira >= 10000) {
            return '₦' . rtrim(rtrim(number_format($naira / 1000, 1, '.', ''), '0'), '.') . 'k';
        }
        return '₦' . number_format($naira);
    }
}
