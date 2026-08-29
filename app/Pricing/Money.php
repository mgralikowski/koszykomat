<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * An exact money amount, held as a decimal string and computed with BCMath.
 *
 * This is the ONLY class in the codebase permitted to call a bc* function, and the reason is
 * `bcmath.scale = 0`: with the default scale, `bcadd('3.49', '0.00')` returns '3' and
 * `bcmul('4.99', '2')` returns '9'. The fractional part is truncated — not rounded — silently
 * and with no warning, producing a basket total that looks plausible and is wrong by złoty.
 * Every call below therefore passes self::SCALE explicitly.
 *
 * A global `bcscale(2)` would be the tempting shortcut, but it is process-global mutable state
 * that PHPUnit, `artisan tinker` and queue workers can each bootstrap differently — a green test
 * suite would then prove nothing about the request path. Explicit scale, one file, no exceptions.
 *
 * Amounts never pass through a float. The constructor takes strings only, which is what
 * Eloquent's `decimal:2` cast returns; accepting a float would be the door drift comes back in.
 */
final readonly class Money
{
    public const int SCALE = 2;

    private function __construct(private string $amount) {}

    /**
     * Build from a decimal string — typically a `decimal:2` attribute off a PriceEntry.
     */
    public static function fromDecimalString(string $amount): self
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Not a numeric money amount: [{$amount}].");
        }

        // Adding zero at an explicit scale normalizes '3.5' and '3.500' to '3.50'.
        return new self(bcadd($amount, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /**
     * Multiply by a whole number of items — the only multiplication the pricing engine performs.
     */
    public function times(int $multiplier): self
    {
        return new self(bcmul($this->amount, (string) $multiplier, self::SCALE));
    }

    public function isLessThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) < 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function isZero(): bool
    {
        return $this->equals(self::zero());
    }

    /**
     * The canonical decimal representation, always at SCALE decimal places.
     */
    public function toDecimalString(): string
    {
        return $this->amount;
    }

    /**
     * Polish currency text for display, e.g. "62,43 zł".
     */
    public function format(): string
    {
        return str_replace('.', ',', $this->amount).' zł';
    }
}
