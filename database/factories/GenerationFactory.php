<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Shaped like the Pixelforge docs' lighthouse example that the tests'
     * pixelforgeGeneration() reports, so its snapshots stay within this
     * Generation's Maximum Price.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'size' => Size::Landscape,
            'quality' => Quality::High,
            'reference_images' => ['https://example.com/style.png'],
            'status' => GenerationStatus::Pending,
            // The Maximum Price of the lighthouse prompt (45 characters) at the published rates.
            'max_price' => Money::ofNanodollars(457_150_000),
        ];
    }

    /**
     * Accepted by Pixelforge, which locked its published rates.
     */
    public function accepted(GenerationStatus $status = GenerationStatus::Queued): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ])->afterMaking(function (Generation $generation): void {
            $generation->pixelforge_id ??= 'gen_'.Str::upper(Str::random(26));
            $generation->input_text_token_rate = 5_000;
            $generation->input_image_token_rate = 10_000;
            $generation->output_token_rate = 40_000;
            $generation->reasoning_token_rate = 12_500;
        });
    }

    /**
     * Succeeded with the usage of the Pixelforge docs' example, at its published rates.
     */
    public function succeeded(): static
    {
        return $this->accepted(GenerationStatus::Succeeded)->afterMaking(function (Generation $generation): void {
            $generation->input_text_tokens = 12;
            $generation->input_image_tokens = 765;
            $generation->output_tokens = 6_450;
            $generation->reasoning_tokens = 3_184;
            $generation->provider_cost = Money::ofNanodollars(305_510_000);
            $generation->price = Money::ofNanodollars(381_887_500);
        });
    }

    /**
     * Failed after Pixelforge accepted it.
     */
    public function failed(): static
    {
        return $this->accepted(GenerationStatus::Failed)->afterMaking(function (Generation $generation): void {
            $generation->error_code = 'generation_failed';
            $generation->error_message = "The model couldn't produce an image for this prompt.";
        });
    }
}
