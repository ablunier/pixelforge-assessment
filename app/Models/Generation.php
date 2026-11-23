<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Money\Money;
use App\Pricing\Rates;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to produce an image, tracked from submission to a final outcome.
 * Status and money only change through App\Actions\GenerationLifecycle.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property Money $max_price
 * @property int|null $input_text_token_rate
 * @property int|null $input_image_token_rate
 * @property int|null $output_token_rate
 * @property int|null $reasoning_token_rate
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property Money|null $provider_cost
 * @property Money|null $price
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_path
 * @property int $submission_attempts
 * @property bool $submission_outcome_unknown
 * @property string|null $provider_image_url
 * @property CarbonImmutable|null $provider_image_url_expires_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['prompt', 'size', 'quality', 'reference_images', 'status', 'max_price'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /** How many of a user's newest Generations the history shows. */
    public const int HISTORY_LIMIT = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'status' => GenerationStatus::class,
            'max_price' => AsMoney::class,
            'input_text_token_rate' => 'integer',
            'input_image_token_rate' => 'integer',
            'output_token_rate' => 'integer',
            'reasoning_token_rate' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'submission_attempts' => 'integer',
            'submission_outcome_unknown' => 'boolean',
            'provider_cost' => AsMoney::class,
            'price' => AsMoney::class,
            'provider_image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The newest Generations, newest first, as the history shows them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function history(Builder $query): void
    {
        $query->latest()->orderByDesc('id')->limit(self::HISTORY_LIMIT);
    }

    /**
     * Generations that haven't reached a final status yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notFinal(Builder $query): void
    {
        $query->whereIn('status', array_filter(
            GenerationStatus::cases(),
            fn (GenerationStatus $status): bool => ! $status->isFinal(),
        ));
    }

    /**
     * What the user chose for this Generation, as Try again reuses them.
     */
    public function parameters(): GenerationParameters
    {
        return new GenerationParameters($this->prompt, $this->size, $this->quality, $this->reference_images);
    }

    /**
     * The rates Pixelforge locked when it accepted this Generation, once known.
     */
    public function lockedRates(): ?Rates
    {
        if ($this->input_text_token_rate === null || $this->input_image_token_rate === null
            || $this->output_token_rate === null || $this->reasoning_token_rate === null) {
            return null;
        }

        return new Rates(
            Money::ofNanodollars($this->input_text_token_rate),
            Money::ofNanodollars($this->input_image_token_rate),
            Money::ofNanodollars($this->output_token_rate),
            Money::ofNanodollars($this->reasoning_token_rate),
        );
    }

    /**
     * Whether Pixelforge's signed image URL is known and hasn't expired yet.
     */
    public function hasLiveProviderImageUrl(): bool
    {
        return $this->provider_image_url !== null
            && $this->provider_image_url_expires_at?->isFuture() === true;
    }

    public function lockRates(Rates $rates): void
    {
        $this->input_text_token_rate = $rates->inputTextToken->nanodollars();
        $this->input_image_token_rate = $rates->inputImageToken->nanodollars();
        $this->output_token_rate = $rates->outputToken->nanodollars();
        $this->reasoning_token_rate = $rates->reasoningToken->nanodollars();
    }
}
