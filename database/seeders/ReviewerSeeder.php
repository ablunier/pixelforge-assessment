<?php

namespace Database\Seeders;

use App\Console\Commands\CreateReviewer;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pricing\Pricing;
use App\Pricing\Rates;
use App\Pricing\Usage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

/**
 * The reviewer account plus example history for local development, so the
 * dashboard has something to show without a Pixelforge key.
 *
 * These Generations are made up (their `gen_` ids exist nowhere), so they're
 * never seeded in production, where every Generation must match Pixelforge's
 * own records: there, `php artisan reviewer:create --examples` submits real
 * ones instead. Money follows the real rules: the Price is the Provider Cost
 * at the locked rates plus the Markup, within the Maximum Price, and
 * failures have neither.
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

    public function run(): void
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
        $this->succeeded($reviewer, $prompts['[succeed]'], Size::Landscape, Quality::High, ['https://example.com/style.png'], new Usage(12, 765, 6_450, 3_184));

        // Each Webhook Event arrived twice; the second delivery of each was a no-op.
        $duplicate = $this->succeeded($reviewer, $prompts['[duplicate]'], Size::Square, Quality::Low, [], new Usage(14, 0, 280, 312));
        $this->webhookEventsFor($duplicate);

        $this->failed($reviewer, $prompts['[fail]'], GenerationErrorCode::GenerationFailed, "The model couldn't produce an image for this prompt.", acceptedByPixelforge: true);
        $this->failed($reviewer, $prompts['[blocked]'], GenerationErrorCode::ContentPolicyViolation, 'This prompt was rejected by our content policy.', acceptedByPixelforge: false);
    }

    /**
     * @param  list<string>  $referenceImages
     */
    private function succeeded(User $reviewer, string $prompt, Size $size, Quality $quality, array $referenceImages, Usage $usage): Generation
    {
        $generation = $this->generation($reviewer, $prompt, $size, $quality, $referenceImages, acceptedByPixelforge: true);
        $lockedRates = $generation->lockedRates() ?? throw new LogicException('Example rates not locked.');

        $generation->status = GenerationStatus::Succeeded;
        $generation->input_text_tokens = $usage->inputTextTokens;
        $generation->input_image_tokens = $usage->inputImageTokens;
        $generation->output_tokens = $usage->outputTokens;
        $generation->reasoning_tokens = $usage->reasoningTokens;
        $generation->provider_cost = Pricing::providerCost($usage, $lockedRates);
        $generation->price = Pricing::price($generation->provider_cost);
        $generation->save();

        return $generation;
    }

    private function failed(User $reviewer, string $prompt, GenerationErrorCode $code, string $message, bool $acceptedByPixelforge): void
    {
        $generation = $this->generation($reviewer, $prompt, Size::Square, Quality::Low, [], $acceptedByPixelforge);
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = $code->value;
        $generation->error_message = $message;
        $generation->save();
    }

    /**
     * @param  list<string>  $referenceImages
     */
    private function generation(User $reviewer, string $prompt, Size $size, Quality $quality, array $referenceImages, bool $acceptedByPixelforge): Generation
    {
        $rates = Rates::fromPixelforge(self::RATES);

        $generation = $reviewer->generations()->make([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'status' => GenerationStatus::Pending,
            'max_price' => Pricing::priceRange(mb_strlen($prompt), $size, $quality, count($referenceImages), $rates)->max,
        ]);

        if ($acceptedByPixelforge) {
            $generation->pixelforge_id = 'gen_'.Str::upper(Str::random(26));
            $generation->submission_attempts = 1;
            $generation->lockRates($rates);
            $generation->provider_created_at = now()->subSeconds(30);
            $generation->started_at = now()->subSeconds(28);
            $generation->completed_at = now();
        }

        return $generation;
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
