<?php

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Pricing\Rates;
use App\Pricing\Usage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\CreateRequest;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;
use Tests\Pixelforge\Reported;

beforeEach(function () {
    // On the docs' timeline, so a succeeded Generation's image URL hasn't expired yet.
    $this->travelTo('2026-11-04T14:03:00Z');
});

/**
 * Press Generate with the docs' lighthouse example. The queue runs synchronously,
 * so this also runs the submit job.
 */
function generateLighthouse(?User $user = null): Generation
{
    test()->actingAs($user ?? User::factory()->withTopUp()->create())
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
        ])
        ->assertRedirect(route('generate'));

    return Generation::query()->latest('id')->firstOrFail();
}

it('submits a Generation to Pixelforge under its local id and records the Generation Pixelforge accepted', function () {
    $generation = generateLighthouse();

    expect(pixelforge()->createRequests())->toEqual([new CreateRequest(
        generationId: $generation->id,
        prompt: 'A lighthouse on a cliff at dawn, oil painting',
        size: Size::from('1536x1024'),
        quality: Quality::High,
        referenceImages: ['https://example.com/style.png'],
    )])
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe(InMemoryPixelforgeClient::pixelforgeIdFor($generation->id))
        ->and($generation->price)->toBeNull();
});

function reconcile(): void
{
    test()->artisan('generations:reconcile')->assertSuccessful();
}

it('polls a Generation from queued through processing to succeeded and records its exact Price once', function () {
    $generation = generateLighthouse();
    pixelforge()->answerRetrieves($generation, Reported::processing(), Reported::succeeded());

    $this->travel(61)->seconds();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->price)->toBeNull();

    $this->travel(61)->seconds();
    reconcile();
    $generation->refresh();

    // 12×5,000 + 765×10,000 + 6,450×40,000 + 3,184×12,500 nanodollars, then ×5/4.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500)
        ->and($generation->output_tokens)->toBe(6_450)
        ->and($generation->reasoning_tokens)->toBe(3_184)
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($generation->error_code)->toBeNull();

    // Final now: later runs leave it alone.
    $this->travel(61)->seconds();
    reconcile();

    expect(pixelforge()->retrievesOf($generation))->toBe(2);
});

it('caps the Price at the Maximum Price when Pixelforge locked higher rates than the estimate used, recording the true Provider Cost', function () {
    // Pixelforge doubled its rates between the estimate and accepting the Generation.
    $doubled = Rates::fromPixelforge(['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00']);
    pixelforge()->answerCreates(Reported::queued(lockedRates: $doubled));
    $generation = generateLighthouse();
    pixelforge()->answerRetrieves($generation, Reported::succeeded());

    $this->travel(61)->seconds();
    reconcile();
    $generation->refresh();

    // Maximum Price at the published rates: (14×5,000 + 765×10,000 + 6,450×40,000 + 8,000×12,500) × 5/4.
    // Provider Cost at the locked rates: 12×10,000 + 765×20,000 + 6,450×80,000 + 3,184×25,000.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->max_price->nanodollars())->toBe(457_150_000)
        ->and($generation->provider_cost?->nanodollars())->toBe(611_020_000)
        ->and($generation->price?->nanodollars())->toBe(457_150_000);
});

it('runs reconciliation every minute, one run at a time', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'generations:reconcile'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('* * * * *')
        ->and($events->sole()->withoutOverlapping)->toBeTrue();
});

it('only polls Generations that have not changed in over 60 seconds', function () {
    $generation = generateLighthouse();
    pixelforge()->answerRetrieves($generation, Reported::processing());

    $this->travel(59)->seconds();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(pixelforge()->retrievesOf($generation))->toBe(0);
});

it('records the error and no Price when a Generation fails after acceptance', function () {
    $generation = generateLighthouse();
    pixelforge()->answerRetrieves($generation, Reported::failed('generation_failed', "The model couldn't produce an image for this prompt."));

    $this->travel(61)->seconds();
    reconcile();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe("The model couldn't produce an image for this prompt.")
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull();
});

/**
 * Two reconciliation runs overlap: while the first run's poll is in flight, a
 * second run polls and applies `$first`. Then the first run's poll returns
 * `$late`, a snapshot older than what the Generation has already reached.
 */
function reconcileOverlapping(Generation $generation, Reported $first, Reported $late): void
{
    pixelforge()->answerRetrieves($generation, function () use ($late) {
        reconcile();

        return $late;
    }, $first);

    reconcile();

    expect(pixelforge()->retrievesOf($generation))->toBe(2);
}

it('ignores a stale processing snapshot that arrives after the Generation succeeded', function () {
    $generation = generateLighthouse();
    $this->travel(61)->seconds();

    reconcileOverlapping($generation, first: Reported::succeeded(), late: Reported::processing());
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('keeps the first Price when a second succeeded snapshot arrives', function () {
    $generation = generateLighthouse();
    $this->travel(61)->seconds();

    $differentUsage = Reported::succeeded(new Usage(14, 765, 6_450, 8_000));

    reconcileOverlapping($generation, first: Reported::succeeded(), late: $differentUsage);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->reasoning_tokens)->toBe(3_184)
        ->and($generation->provider_cost?->nanodollars())->toBe(305_510_000)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('never fails a Generation that already succeeded', function () {
    $generation = generateLighthouse();
    $this->travel(61)->seconds();

    reconcileOverlapping($generation, first: Reported::succeeded(), late: Reported::failed('internal_error', 'Something went wrong on our side.'));
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->error_code)->toBeNull()
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('keeps polling the other Generations when one poll returns a response this app can\'t read', function () {
    Exceptions::fake();
    $unreadable = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_UNREADABLE', 'updated_at' => now()->subMinutes(2)]);
    $later = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'updated_at' => now()->subMinutes(2)]);
    pixelforge()->answerRetrieves($unreadable, NoAnswer::Unreadable);
    pixelforge()->answerRetrieves($later, Reported::processing());

    reconcile();

    expect($unreadable->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($later->refresh()->status)->toBe(GenerationStatus::Processing);
    Exceptions::assertReported(UnexpectedPixelforgeResponse::class);
});

/** A success whose output tokens don't fit their column, so recording it fails in the database. */
function unrecordableSuccess(): Reported
{
    return Reported::succeeded(new Usage(12, 765, 3_000_000_000, 3_184));
}

it('keeps polling the other Generations when recording one poll\'s result fails', function () {
    Exceptions::fake();
    $unrecordable = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_UNRECORDABLE', 'updated_at' => now()->subMinutes(2)]);
    $later = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'updated_at' => now()->subMinutes(2)]);
    pixelforge()->answerRetrieves($unrecordable, unrecordableSuccess());
    pixelforge()->answerRetrieves($later, Reported::processing());

    reconcile();

    expect($unrecordable->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($later->refresh()->status)->toBe(GenerationStatus::Processing);
    Exceptions::assertReported(QueryException::class);
});

it('gives up on a Generation whose last poll\'s result can\'t be recorded, once an hour has passed', function () {
    Exceptions::fake();
    $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create(['pixelforge_id' => 'gen_UNRECORDABLE']);
    pixelforge()->answerRetrieves($generation, unrecordableSuccess());

    $this->travel(Generation::GIVE_UP_AFTER_MINUTES + 1)->minutes();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('never_finished');
    Exceptions::assertReported(QueryException::class);
});

it('gives up on a Generation Pixelforge hasn\'t finished an hour after Generate, with no Price and a readable reason', function () {
    $generation = Generation::factory()->accepted(GenerationStatus::Processing)
        ->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    pixelforge()->answerRetrieves($generation, Reported::processing());

    $this->travel(Generation::GIVE_UP_AFTER_MINUTES - 1)->minutes();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);

    $this->travel(2)->minutes();
    reconcile();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('never_finished')
        ->and($generation->provider_cost)->toBeNull()
        ->and($generation->price)->toBeNull();

    $this->actingAs($generation->user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.error.reason', 'Pixelforge never finished it')
            ->where('pollHistory', false));
});

it('gives up on a Generation Pixelforge no longer knows, once an hour has passed', function () {
    Exceptions::fake();
    $generation = Generation::factory()->accepted()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    // Pixelforge holds no Generation with its `gen_` id.

    $this->travel(Generation::GIVE_UP_AFTER_MINUTES + 1)->minutes();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('never_finished');
});

it('records the Price when the last poll before giving up finds the Generation succeeded', function () {
    $generation = Generation::factory()->accepted(GenerationStatus::Processing)
        ->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    pixelforge()->answerRetrieves($generation, Reported::succeeded());

    $this->travel(Generation::GIVE_UP_AFTER_MINUTES + 1)->minutes();
    reconcile();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->price?->nanodollars())->toBe(381_887_500);
});

it('never changes a final Generation, not even to record a gen_ id or rates a later snapshot reports', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Failed,
    ]);
    $before = $generation->fresh()->getAttributes();

    app(GenerationLifecycle::class)->apply($generation, Reported::queued()->snapshot('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'));

    expect($generation->fresh()->getAttributes())->toBe($before)
        ->and($generation->fresh()->pixelforge_id)->toBeNull()
        ->and($generation->fresh()->lockedRates())->toBeNull();
});
