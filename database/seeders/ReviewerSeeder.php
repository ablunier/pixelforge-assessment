<?php

namespace Database\Seeders;

use App\Actions\GenerationLifecycle;
use App\Console\Commands\CreateReviewer;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationErrorCode;
use App\Pixelforge\GenerationSnapshot;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use App\Pricing\Usage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The reviewer account plus example history for local development, so the
 * dashboard has something to show without a Pixelforge key.
 *
 * These Generations are made up (their `gen_` ids exist nowhere), so they're
 * never seeded in production, where every Generation must match Pixelforge's
 * own records: there, `php artisan reviewer:create --examples` submits real
 * ones instead. Each example is created `pending` and then finished through
 * GenerationLifecycle with a made-up Pixelforge snapshot, so its status and
 * money follow the real rules. The snapshots carry no image URL, so no image
 * download is queued and the history shows the "image expired" placeholder.
 */
class ReviewerSeeder extends Seeder
{
    /** @var array<string, string> Pixelforge's published rates, as locked on each example. */
    private const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function run(GenerationLifecycle $lifecycle): void
    {
        $this->command->call('reviewer:create');

        if (app()->isProduction()) {
            $this->command->warn('Not seeding made-up Generations in production. Run `php artisan reviewer:create --examples` to submit real ones.');

            return;
        }

        $reviewer = User::query()->where('email', config('reviewer.email'))->sole();

        if ($reviewer->generations()->exists()) {
            return;
        }

        $prompts = CreateReviewer::EXAMPLE_PROMPTS;

        // The Pixelforge docs' example usage.
        $succeeded = $this->pending($reviewer, $prompts['[succeed]'], Size::Landscape, Quality::High, ['https://example.com/style.png']);
        $lifecycle->apply($succeeded, $this->snapshot($succeeded, GenerationStatus::Succeeded, usage: new Usage(12, 765, 6_450, 3_184)));

        // Each Webhook Event arrived twice; the second delivery of each was a no-op.
        $duplicate = $this->pending($reviewer, $prompts['[duplicate]'], Size::Square, Quality::Low, []);
        $duplicate = $lifecycle->apply($duplicate, $this->snapshot($duplicate, GenerationStatus::Succeeded, usage: new Usage(14, 0, 280, 312)));
        $this->webhookEventsFor($duplicate);

        $failed = $this->pending($reviewer, $prompts['[fail]'], Size::Square, Quality::Low, []);
        $lifecycle->apply($failed, $this->snapshot($failed, GenerationStatus::Failed, error: [GenerationErrorCode::GenerationFailed, "The model couldn't produce an image for this prompt."]));

        $blocked = $this->pending($reviewer, $prompts['[blocked]'], Size::Square, Quality::Low, []);
        $lifecycle->failSubmission($blocked, GenerationErrorCode::ContentPolicyViolation, 'This prompt was rejected by our content policy.');
    }

    /**
     * A Generation as Generate saves it, after its one create request.
     *
     * @param  list<string>  $referenceImages
     */
    private function pending(User $reviewer, string $prompt, Size $size, Quality $quality, array $referenceImages): Generation
    {
        $generation = $reviewer->generations()->make([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'status' => GenerationStatus::Pending,
            'max_price' => Pricing::priceRange(mb_strlen($prompt), $size, $quality, count($referenceImages), Rates::fromPixelforge(self::RATES))->max,
        ]);
        $generation->submission_attempts = 1;
        $generation->save();

        return $generation;
    }

    /**
     * What Pixelforge would report for a finished example.
     *
     * @param  array{GenerationErrorCode, string}|null  $error
     */
    private function snapshot(Generation $generation, GenerationStatus $status, ?Usage $usage = null, ?array $error = null): GenerationSnapshot
    {
        return new GenerationSnapshot(
            pixelforgeId: 'gen_'.Str::upper(Str::random(26)),
            status: $status,
            generationId: $generation->id,
            rates: Rates::fromPixelforge(self::RATES),
            usage: $usage,
            errorCode: $error[0]->value ?? null,
            errorMessage: $error[1] ?? null,
            imageUrl: null,
            imageUrlExpiresAt: null,
            createdAt: now()->subSeconds(30)->toImmutable(),
            startedAt: now()->subSeconds(28)->toImmutable(),
            completedAt: now()->toImmutable(),
        );
    }

    private function webhookEventsFor(Generation $generation): void
    {
        foreach (['generation.processing', 'generation.succeeded'] as $type) {
            $event = new WebhookEvent([
                'event_id' => 'evt_'.Str::upper(Str::random(26)),
                'type' => $type,
                'payload' => (string) json_encode(['type' => $type, 'data' => ['object' => ['id' => $generation->pixelforge_id, 'metadata' => ['generation_id' => $generation->id]]]]),
                'delivery_attempt' => 1,
                'received_at' => now(),
            ]);
            $event->generation_id = $generation->id;
            $event->processed_at = now();
            $event->save();
        }
    }
}
