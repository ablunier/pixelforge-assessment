<?php

use App\Credits\InsufficientAvailableBalance;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingUnavailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Pixelforge\NoAnswer;

/**
 * Run reviewer:create and return what it printed.
 *
 * @param  array<string, mixed>  $options
 */
function createReviewer(array $options = []): string
{
    expect(Artisan::call('reviewer:create', $options))->toBe(0);

    return Artisan::output();
}

/**
 * The password reviewer:create printed, if it printed one.
 */
function printedPassword(string $output): ?string
{
    return preg_match('/Password: (\S+)/', $output, $matches) === 1 ? $matches[1] : null;
}

it('creates the reviewer account with the configured credentials', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);

    $this->artisan('reviewer:create')
        ->doesntExpectOutputToContain('a-long-secret-password')
        ->assertSuccessful();

    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => 'a-long-secret-password'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::query()->where('email', 'reviewer@example.com')->sole());
});

it('generates and prints a password when none is configured', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => null]);

    $password = printedPassword(createReviewer());

    expect($password)->not->toBeNull()->and(strlen((string) $password))->toBeGreaterThanOrEqual(24);
    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => $password])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

it('keeps the existing password when run again without one configured', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => null]);
    $password = printedPassword(createReviewer());

    $secondRun = createReviewer();

    expect(printedPassword($secondRun))->toBeNull()
        ->and(User::query()->where('email', 'reviewer@example.com')->count())->toBe(1);
    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => $password]);
    $this->assertAuthenticated();
});

it('changes the password when run again with a new one configured', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'the-first-password']);
    createReviewer();

    config(['reviewer.password' => 'the-second-password']);
    createReviewer();

    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => 'the-first-password']);
    $this->assertGuest();
    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => 'the-second-password']);
    $this->assertAuthenticated();
});

/**
 * The reviewer account already set up, with Credits to Hold every example.
 */
function fundedReviewer(): User
{
    return User::factory()->withTopUp()->create(['email' => 'reviewer@example.com']);
}

it('submits one Generation per sandbox keyword to Pixelforge with --examples', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();
    fundedReviewer();

    createReviewer(['--examples' => true]);

    $generations = User::query()->where('email', 'reviewer@example.com')->sole()->generations;
    expect($generations)->toHaveCount(6);

    foreach (['[succeed]', '[fail]', '[slow]', '[duplicate]', '[blocked]', '[overloaded]'] as $keyword) {
        $generation = $generations->sole(fn (Generation $generation): bool => str_contains($generation->prompt, $keyword));
        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->max_price->nanodollars())->toBeGreaterThan(0);
        Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job): bool => $job->generation->is($generation));
    }
});

it('only submits the examples the reviewer is missing when run again', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();
    fundedReviewer();
    createReviewer(['--examples' => true]);
    Generation::query()->where('prompt', 'like', '%[slow]%')->delete();

    createReviewer(['--examples' => true]);

    expect(Generation::query()->count())->toBe(6)
        ->and(Generation::query()->where('prompt', 'like', '%[slow]%')->count())->toBe(1);
    Queue::assertPushed(SubmitGenerationToPixelforge::class, 7);
});

it('stops with the pricing failure, submitting nothing, when there are no rates to price the examples with', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    pixelforge()->answerRates(NoAnswer::Unreachable);
    Queue::fake();

    expect(fn () => Artisan::call('reviewer:create', ['--examples' => true]))->toThrow(PricingUnavailable::class);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stops, submitting no example it can\'t Hold, when the reviewer lacks the Credits', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();

    expect(fn () => Artisan::call('reviewer:create', ['--examples' => true]))->toThrow(InsufficientAvailableBalance::class);

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('submits nothing to Pixelforge without --examples', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();

    createReviewer();

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
