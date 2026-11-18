<?php

use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    fakePixelforgePricing();
});

const LIGHTHOUSE_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

const LIGHTHOUSE_IMAGE_URL = 'https://images.pixelforge.test/lighthouse.png';

const LIGHTHOUSE_PNG = "\x89PNG\r\n\x1a\nlighthouse";

/**
 * A lighthouse Generation that Pixelforge is processing and that has gone quiet,
 * so the next reconciliation run polls it. The clock is left shortly after it
 * completes, well before its image URL expires (2026-11-05T14:03:29Z).
 */
function processingLighthouse(?User $user = null): Generation
{
    test()->travelTo('2026-11-04T14:03:20Z');

    $generation = Generation::factory()->for($user ?? User::factory()->create())
        ->accepted(GenerationStatus::Processing)
        ->create(['pixelforge_id' => LIGHTHOUSE_ID]);

    test()->travelTo('2026-11-04T14:05:00Z');

    return $generation;
}

/**
 * Pixelforge answers each poll of the lighthouse with the next of these
 * succeeded Generations' overrides.
 *
 * @param  array<string, mixed>  ...$polls
 */
function fakeLighthouseSucceeds(array ...$polls): void
{
    $sequence = Http::sequence();

    foreach ($polls ?: [[]] as $overrides) {
        $sequence->push(pixelforgeGeneration('succeeded', ['image_url' => LIGHTHOUSE_IMAGE_URL, ...$overrides]));
    }

    Http::fake(['pixelforge.test/v1/generations/'.LIGHTHOUSE_ID => $sequence]);
}

/**
 * Pixelforge serves the lighthouse PNG at its image URL.
 */
function fakeLighthouseImage(): void
{
    Http::fake([LIGHTHOUSE_IMAGE_URL => Http::response(LIGHTHOUSE_PNG, 200, ['Content-Type' => 'image/png'])]);
}

/**
 * Queue jobs for a worker rather than running them on dispatch.
 */
function queueJobsForWorker(): void
{
    config(['queue.default' => 'database']);
}

/**
 * A queue worker runs every job that's due.
 */
function runQueueWorker(): void
{
    test()->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
}

/**
 * @return array<string, mixed>|null
 */
function historyImage(User $user): ?array
{
    $image = null;

    test()->actingAs($user)->get(route('dashboard'))
        ->assertInertia(function (Assert $page) use (&$image) {
            $image = $page->toArray()['props']['generations'][0]['image'];

            return $page;
        });

    return $image;
}

it('stores the image of a succeeded Generation and serves it to its owner', function () {
    $user = User::factory()->create();
    $generation = processingLighthouse($user);
    fakeLighthouseSucceeds();
    fakeLighthouseImage();

    $this->artisan('generations:reconcile')->assertSuccessful();

    $response = $this->actingAs($user)->get(route('generations.image', $generation))->assertOk();

    expect($response->streamedContent())->toBe(LIGHTHOUSE_PNG)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and(historyImage($user))->toBe([
            'source' => 'stored',
            'url' => route('generations.image', $generation),
            'downloadUrl' => route('generations.image.download', $generation),
            'expiresAt' => null,
        ]);
});

it('lets the owner download the full-size image', function () {
    $user = User::factory()->create();
    $generation = processingLighthouse($user);
    fakeLighthouseSucceeds();
    fakeLighthouseImage();
    $this->artisan('generations:reconcile')->assertSuccessful();

    $response = $this->actingAs($user)->get(route('generations.image.download', $generation))->assertOk();

    expect($response->streamedContent())->toBe(LIGHTHOUSE_PNG)
        ->and($response->headers->get('Content-Disposition'))->toContain("pixelforge-{$generation->id}.png");
});

it('denies another user the image of a Generation', function () {
    $generation = processingLighthouse();
    fakeLighthouseSucceeds();
    fakeLighthouseImage();
    $this->artisan('generations:reconcile')->assertSuccessful();

    $this->actingAs(User::factory()->create());

    $this->get(route('generations.image', $generation))->assertForbidden();
    $this->get(route('generations.image.download', $generation))->assertForbidden();
});

it('shows the provider image in the history until the download completes', function () {
    $user = User::factory()->create();
    $generation = processingLighthouse($user);
    fakeLighthouseSucceeds();
    fakeLighthouseImage();
    queueJobsForWorker();

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(historyImage($user))->toBe([
        'source' => 'provider',
        'url' => LIGHTHOUSE_IMAGE_URL,
        'downloadUrl' => LIGHTHOUSE_IMAGE_URL,
        'expiresAt' => '2026-11-05T14:03:29Z',
    ]);
    $this->get(route('generations.image', $generation))->assertNotFound();

    runQueueWorker();

    expect(historyImage($user)['source'])->toBe('stored');
});

it('retries a failed download with backoff', function () {
    $user = User::factory()->create();
    processingLighthouse($user);
    fakeLighthouseSucceeds();
    Http::fake([LIGHTHOUSE_IMAGE_URL => Http::sequence()->push('Bad gateway', 502)->push(LIGHTHOUSE_PNG)]);
    queueJobsForWorker();
    $this->artisan('generations:reconcile')->assertSuccessful();

    runQueueWorker();
    $this->travel(5)->seconds();
    runQueueWorker();

    expect(historyImage($user)['source'])->toBe('provider');

    $this->travel(6)->seconds();
    runQueueWorker();

    expect(historyImage($user)['source'])->toBe('stored');
});

it('downloads the image again later once every try failed, while the image URL still works', function () {
    $user = User::factory()->create();
    processingLighthouse($user);
    fakeLighthouseSucceeds();
    Http::fake([LIGHTHOUSE_IMAGE_URL => Http::sequence()->dontFailWhenEmpty()
        ->push('Bad gateway', 502)->push('Bad gateway', 502)->push('Bad gateway', 502)
        ->push('Bad gateway', 502)->push('Bad gateway', 502)
        ->push(LIGHTHOUSE_PNG)]);
    queueJobsForWorker();
    $this->artisan('generations:reconcile')->assertSuccessful();

    // StoreGenerationImage's own tries, 10 s to 10 min apart, all fail.
    foreach ([0, 10, 30, 120, 600] as $wait) {
        $this->travel($wait + 1)->seconds();
        runQueueWorker();
    }

    expect(historyImage($user)['source'])->toBe('provider');

    $this->travel(ReconcileGenerations::IMAGE_RETRY_AFTER_MINUTES)->minutes();
    $this->artisan('generations:reconcile')->assertSuccessful();
    runQueueWorker();

    expect(historyImage($user)['source'])->toBe('stored');
});

it('stops downloading the image again once the image URL has expired', function () {
    $user = User::factory()->create();
    $generation = processingLighthouse($user);
    fakeLighthouseSucceeds();
    queueJobsForWorker();
    $this->artisan('generations:reconcile')->assertSuccessful();
    $generation->refresh();
    Queue::fake();

    $this->travelTo('2026-11-05T14:03:30Z');
    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertNotPushed(StoreGenerationImage::class);
});

it('refuses to store a download that isn\'t a PNG', function () {
    $user = User::factory()->create();
    processingLighthouse($user);
    fakeLighthouseSucceeds();
    Http::fake([LIGHTHOUSE_IMAGE_URL => Http::response('<html>Sign in to continue</html>', 200, ['Content-Type' => 'text/html'])]);
    queueJobsForWorker();
    $this->artisan('generations:reconcile')->assertSuccessful();

    runQueueWorker();

    expect(historyImage($user)['source'])->toBe('provider')
        ->and(Storage::disk(StoreGenerationImage::DISK)->allFiles())->toBe([]);
});

it('fetches a fresh signed URL from Pixelforge when the stored one has expired', function () {
    $user = User::factory()->create();
    $generation = processingLighthouse($user);
    fakeLighthouseSucceeds(
        ['image_url' => 'https://images.pixelforge.test/stale.png', 'image_url_expires_at' => '2026-11-04T14:04:00Z'],
        ['image_url' => LIGHTHOUSE_IMAGE_URL],
    );
    fakeLighthouseImage();

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($this->actingAs($user)->get(route('generations.image', $generation))->streamedContent())->toBe(LIGHTHOUSE_PNG);
    Http::assertNotSent(fn ($request) => $request->url() === 'https://images.pixelforge.test/stale.png');
});

it('shows an expired image once the provider URL has expired without a stored copy', function () {
    $user = User::factory()->create();
    processingLighthouse($user);
    fakeLighthouseSucceeds();
    queueJobsForWorker();
    $this->artisan('generations:reconcile')->assertSuccessful();

    $this->travelTo('2026-11-05T14:03:30Z');

    expect(historyImage($user))->toBe([
        'source' => 'expired',
        'url' => null,
        'downloadUrl' => null,
        'expiresAt' => '2026-11-05T14:03:29Z',
    ]);
});

it('has no image until a Generation succeeds', function () {
    $generation = processingLighthouse();

    expect(historyImage($generation->user))->toBeNull();
});
