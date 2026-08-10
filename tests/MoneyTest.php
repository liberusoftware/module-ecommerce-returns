<?php

declare(strict_types=1);

use Liberu\Ecommerce\Returns\Data\Money;
use Liberu\Ecommerce\Returns\Exceptions\InvalidMoney;
use Liberu\Ecommerce\Returns\Support\MinorUnits;

/**
 * Money is an integer count of the currency's smallest unit, everywhere.
 *
 * The conversion is the part that gets written wrong, and this file is where the
 * reason survives the person who found it. `docs/adoption.md` points at it,
 * because the host's `refunds.amount` is a `decimal(10,2)` and converting the
 * column is this conversion at scale.
 */
it('parses a decimal string without ever constructing a float', function (string $amount, int $exponent, int $expected) {
    expect(MinorUnits::fromDecimalString($amount, $exponent))->toBe($expected);
})->with([
    'the one that breaks' => ['19.99', 2, 1999],
    'a whole number' => ['20', 2, 2000],
    'a trailing point' => ['20.', 2, 2000],
    'one decimal place' => ['19.9', 2, 1990],
    'zero' => ['0.00', 2, 0],
    'negative' => ['-19.99', 2, -1999],
    'explicitly positive' => ['+5.05', 2, 505],
    'a zero-exponent currency' => ['1234', 0, 1234],
    'a three-exponent currency' => ['1.234', 3, 1234],
    'padded with whitespace' => ['  19.99  ', 2, 1999],
]);

it('is not what multiplying by a hundred does', function () {
    // `(int) (19.99 * 100)` is 1998, because 19.99 is not representable in binary
    // floating point and the cast truncates what is left. Written out next to the
    // right answer so the reason cannot be lost.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(MinorUnits::fromDecimalString('19.99'))->toBe(1999);
});

it('refuses an amount more precise than its currency, rather than rounding it', function () {
    // Rounding `19.995` needs a rule — half up, half even, towards the merchant —
    // and picking one silently on somebody's refund is not this module's decision.
    MinorUnits::fromDecimalString('19.995', 2);
})->throws(InvalidMoney::class, 'Round it before converting');

it('refuses anything that is not a decimal amount', function (string $amount) {
    MinorUnits::fromDecimalString($amount);
})->with([
    'a currency symbol' => ['£19.99'],
    'a thousands separator' => ['1,999.00'],
    'words' => ['nineteen'],
    'empty' => [''],
    'two points' => ['19.9.9'],
])->throws(InvalidMoney::class);

it('refuses a negative exponent in either direction', function (callable $call) {
    $call();
})->with([
    // One level of closure, not two: a closure inside a dataset row is passed
    // through untouched rather than called, so this *is* the value under test.
    'parsing' => [fn () => MinorUnits::fromDecimalString('19.99', -1)],
    'rendering' => [fn () => MinorUnits::toDecimalString(1999, -1)],
])->throws(InvalidMoney::class);

it('renders minor units as a string, never a float', function (int $minor, int $exponent, string $expected) {
    expect(MinorUnits::toDecimalString($minor, $exponent))->toBe($expected);
})->with([
    'ordinary' => [1999, 2, '19.99'],
    'under a unit' => [5, 2, '0.05'],
    'zero' => [0, 2, '0.00'],
    'negative' => [-1999, 2, '-19.99'],
    'a zero-exponent currency' => [1234, 0, '1234'],
    'a three-exponent currency' => [1234, 3, '1.234'],
]);

it('round-trips every amount it can parse', function (string $amount) {
    expect(MinorUnits::toDecimalString(MinorUnits::fromDecimalString($amount)))->toBe($amount);
})->with(['19.99', '0.00', '-19.99', '1000000.01']);

it('serialises money in the one shape every commerce API here uses', function () {
    $money = new Money(1999, 'GBP');

    expect($money->toArray())
        ->toBe(['minor' => 1999, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '19.99'])
        // `decimal` is a **string**. A JSON number `19.99` is a float the moment
        // it is parsed, which is the entire problem this shape exists to avoid.
        ->and(json_encode($money))->toBe('{"minor":1999,"currency":"GBP","exponent":2,"decimal":"19.99"}');
});
