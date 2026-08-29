<?php

namespace Tests\Unit\Pricing;

use App\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The truncation guard is the reason this class exists.
 *
 * Production runs `bcmath.scale = 0`, where a bc* call that omits an explicit scale silently
 * truncates the fractional part: bcadd('3.49', '0.00') is '3'. setUp() pins the default scale to
 * 0 so these tests reproduce production rather than whatever the local environment happens to
 * bootstrap — without that, the suite could pass everywhere and the verdict would still be wrong
 * on the server.
 */
class MoneyTest extends TestCase
{
    private int $previousScale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousScale = bcscale(0);
    }

    protected function tearDown(): void
    {
        bcscale($this->previousScale);

        parent::tearDown();
    }

    public function test_the_default_scale_is_really_zero_in_these_tests(): void
    {
        // Guards the guard: if this ever fails, the truncation tests below prove nothing.
        $this->assertSame('3', bcadd('3.49', '0.00'));
    }

    public function test_addition_survives_a_zero_default_scale(): void
    {
        $sum = Money::fromDecimalString('3.49')->plus(Money::zero());

        $this->assertSame('3.49', $sum->toDecimalString());
    }

    public function test_multiplication_survives_a_zero_default_scale(): void
    {
        $total = Money::fromDecimalString('4.99')->times(2);

        $this->assertSame('9.98', $total->toDecimalString());
    }

    public function test_subtraction_survives_a_zero_default_scale(): void
    {
        $margin = Money::fromDecimalString('67.46')->minus(Money::fromDecimalString('62.43'));

        $this->assertSame('5.03', $margin->toDecimalString());
    }

    public function test_it_normalizes_scale_on_construction(): void
    {
        $short = Money::fromDecimalString('3.5');
        $long = Money::fromDecimalString('3.50');

        $this->assertSame('3.50', $short->toDecimalString());
        $this->assertTrue($short->equals($long));
        $this->assertSame('3,50 zł', $short->format());
    }

    public function test_a_long_chain_of_additions_stays_exact(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 100; $i++) {
            $total = $total->plus(Money::fromDecimalString('0.01'));
        }

        $this->assertSame('1.00', $total->toDecimalString());
    }

    public function test_it_formats_polish_currency(): void
    {
        $this->assertSame('62,43 zł', Money::fromDecimalString('62.43')->format());
        $this->assertSame('0,00 zł', Money::zero()->format());
    }

    public function test_negative_amounts_round_trip(): void
    {
        $negative = Money::fromDecimalString('1.00')->minus(Money::fromDecimalString('3.50'));

        $this->assertSame('-2.50', $negative->toDecimalString());
        $this->assertSame('-2,50 zł', $negative->format());
    }

    public function test_comparisons_are_numeric_not_lexicographic(): void
    {
        $nine = Money::fromDecimalString('9.99');
        $ten = Money::fromDecimalString('10.00');

        // '9.99' sorts after '10.00' as a string — the comparison must not do that.
        $this->assertTrue($nine->isLessThan($ten));
        $this->assertFalse($ten->isLessThan($nine));
    }

    public function test_it_rejects_a_non_numeric_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('brak ceny');
    }
}
