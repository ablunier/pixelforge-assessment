<?php

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The in-memory Pixelforge the current test's app talks to.
 */
function pixelforge(): InMemoryPixelforgeClient
{
    return test()->pixelforge();
}

/**
 * The ledger entries of `$generation`, as kind => amounts in nanodollars.
 *
 * @return array<string, list<int>>
 */
function ledgerOf(Generation $generation): array
{
    return $generation->user->ledgerEntries()->where('generation_id', $generation->id)->orderBy('id')->get()
        ->groupBy(fn ($entry): string => $entry->kind->value)
        ->map(fn ($entries): array => $entries->map(fn ($entry): int => $entry->amount->nanodollars())->all())
        ->all();
}
