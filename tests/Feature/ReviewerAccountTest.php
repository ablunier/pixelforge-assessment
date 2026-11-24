<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use App\Pixelforge\PricingUnavailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Pixelforge\InMemoryPixelforgeClient;
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

it('brings back a reviewer who deleted their account, with the configured password', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    createReviewer();
    User::query()->where('email', 'reviewer@example.com')->sole()->delete();

    createReviewer();

    expect(User::withTrashed()->where('email', 'reviewer@example.com')->count())->toBe(1);
    $this->post(route('login.store'), ['email' => 'reviewer@example.com', 'password' => 'a-long-secret-password'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();
});

function reviewer(): User
{
    return User::query()->where('email', 'reviewer@example.com')->sole();
}

it('makes the reviewer an admin, so they can see the admin report', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);

    createReviewer();

    $this->actingAs(reviewer())->get(route('admin.report'))->assertOk();
});

it('leaves the reviewer enough Credits after the examples to try every quality and size', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();

    createReviewer(['--examples' => true]);

    // One Generation of each quality and size, with the longest prompt and the most reference images.
    $everyQualityAndSize = Money::zero();
    foreach (Quality::cases() as $quality) {
        foreach (Size::cases() as $size) {
            $parameters = new GenerationParameters(
                str_repeat('a', StoreGenerationRequest::MAX_PROMPT_LENGTH),
                $size,
                $quality,
                array_fill(0, StoreGenerationRequest::MAX_REFERENCE_IMAGES, 'https://example.com/style.png'),
            );
            $everyQualityAndSize = $everyQualityAndSize->plus($parameters->maximumPrice(InMemoryPixelforgeClient::publishedRates()));
        }
    }

    expect(reviewer()->availableBalance()->isLessThan($everyQualityAndSize))->toBeFalse();
});

it('tops the reviewer up only once when run again', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();
    createReviewer(['--examples' => true]);
    $balance = reviewer()->balance();

    createReviewer();
    createReviewer(['--examples' => true]);

    expect(reviewer()->balance()->nanodollars())->toBe($balance->nanodollars());
});

it('adds no Credits to a reviewer who already has plenty', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    User::factory()->withTopUp(Money::fromDecimal('100'))->create(['email' => 'reviewer@example.com']);

    createReviewer();

    expect(reviewer()->balance()->nanodollars())->toBe(Money::fromDecimal('100')->nanodollars());
});

it('funds a new reviewer and submits one Generation per sandbox keyword to Pixelforge with --examples', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();

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
    createReviewer(['--examples' => true]);
    // Generations are never deleted, so an example goes missing by its prompt changing.
    Generation::query()->where('prompt', 'like', '%[slow]%')->update(['prompt' => 'A prompt that is no example']);

    createReviewer(['--examples' => true]);

    expect(Generation::query()->count())->toBe(7)
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

it('submits nothing to Pixelforge without --examples', function () {
    config(['reviewer.email' => 'reviewer@example.com', 'reviewer.password' => 'a-long-secret-password']);
    Queue::fake();

    createReviewer();

    expect(Generation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
