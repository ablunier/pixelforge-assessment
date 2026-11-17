<?php

use App\Money\Money;

it('parses a Pixelforge nine-decimal string to exact nanodollars', function () {
    expect(Money::fromDecimal('0.169080000')->nanodollars())->toBe(169_080_000);
});

it('parses two-decimal rate strings and negative amounts', function () {
    expect(Money::fromDecimal('12.50')->nanodollars())->toBe(12_500_000_000)
        ->and(Money::fromDecimal('-0.000000001')->nanodollars())->toBe(-1);
});

it('rejects strings that are not exact decimal amounts', function (string $decimal) {
    Money::fromDecimal($decimal);
})->throws(InvalidArgumentException::class)->with(['0.0000000001', '1e-9', '', '.5', '5.', 'abc', '1,000.00']);

it('formats to exactly nine decimals', function (int $nanodollars, string $expected) {
    expect(Money::ofNanodollars($nanodollars)->formatExact())->toBe($expected);
})->with([
    [373_875_000, '0.373875000'],
    [12_500_000_000, '12.500000000'],
    [0, '0.000000000'],
    [-1, '-0.000000001'],
]);

it('formats to four decimals for display, rounding half up', function (int $nanodollars, string $expected) {
    expect(Money::ofNanodollars($nanodollars)->formatForDisplay())->toBe($expected);
})->with([
    [373_875_000, '0.3739'],
    [14_062_500, '0.0141'],
    [49_999, '0.0000'],
    [50_000, '0.0001'],
    [999_950_000, '1.0000'],
    [-50_000, '-0.0001'],
]);

it('adds, subtracts and compares amounts', function () {
    $a = Money::ofNanodollars(300);
    $b = Money::ofNanodollars(120);

    expect($a->plus($b)->nanodollars())->toBe(420)
        ->and($b->minus($a)->nanodollars())->toBe(-180)
        ->and($a->isGreaterThan($b))->toBeTrue()
        ->and($a->isLessThan($b))->toBeFalse()
        ->and($a->compareTo($b))->toBe(1)
        ->and($b->compareTo($a))->toBe(-1)
        ->and($a->equals(Money::fromDecimal('0.0000003')))->toBeTrue();
});

it('multiplies by a whole number of units', function () {
    expect(Money::ofNanodollars(12_500)->times(3_184)->nanodollars())->toBe(39_800_000);
});

it('applies the 25% Markup exactly to the nanodollar', function () {
    expect(Money::fromDecimal('0.305510000')->withMarkup()->formatExact())->toBe('0.381887500');
});

it('rounds the Markup half up to the nanodollar when the cost is not divisible by 4', function (int $cost, int $price) {
    expect(Money::ofNanodollars($cost)->withMarkup()->nanodollars())->toBe($price);
})->with([
    [1, 1],   // 1.25
    [2, 3],   // 2.5
    [3, 4],   // 3.75
    [4, 5],
    [-2, -3],
]);
