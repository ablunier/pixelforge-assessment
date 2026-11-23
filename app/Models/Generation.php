<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
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

    /*
     * Reconciliation's timings. `updated_at` is the lease: anything that
     * touches a Generation keeps reconciliation away from it for a while.
     * tests/Unit/ReconciliationTimingTest.php holds them to the job timings
     * they must outlast.
     */

    /**
     * How long a `pending` Generation must go unchanged before it's resubmitted:
     * longer than SubmissionRules::MAX_RETRY_DELAY_SECONDS, so a submit job
     * waiting out a retry delay isn't resubmitted. The submit job is also
     * unique per Generation, so a retry still waiting in a busy queue is never
     * doubled up.
     */
    public const int PENDING_STALE_AFTER_SECONDS = 120;

    /** How long a `queued` or `processing` Generation must go unchanged before it's polled. */
    public const int POLL_STALE_AFTER_SECONDS = 60;

    /**
     * How long after pressing Generate an accepted Generation may stay
     * `queued` or `processing` before it's given up on (ADR-0007).
     */
    public const int GIVE_UP_AFTER_MINUTES = 60;

    /**
     * How long a succeeded Generation without a stored image waits between
     * downloads: longer than a whole run of StoreGenerationImage, its waits
     * between tries and the tries themselves. Time a try spends waiting in the
     * queue or fetching a fresh URL isn't counted, so a busy queue can still
     * see a second download start; that's harmless, as the job skips a
     * Generation whose image is already stored.
     */
    public const int IMAGE_RETRY_AFTER_MINUTES = 16;

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
     * `pending` Generations unchanged for PENDING_STALE_AFTER_SECONDS: their
     * submit job died, or left them for a resend (ADR-0005).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dueForResubmit(Builder $query): void
    {
        $query->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subSeconds(self::PENDING_STALE_AFTER_SECONDS));
    }

    /**
     * Accepted Generations still `queued` or `processing` and unchanged for
     * POLL_STALE_AFTER_SECONDS.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dueForPoll(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds(self::POLL_STALE_AFTER_SECONDS));
    }

    /**
     * Succeeded Generations with no stored image, a live provider image URL,
     * and no download started for IMAGE_RETRY_AFTER_MINUTES.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dueForImageRetry(Builder $query): void
    {
        $query->where('status', GenerationStatus::Succeeded)
            ->whereNull('image_path')
            ->withLiveProviderImageUrl()
            ->where('updated_at', '<', now()->subMinutes(self::IMAGE_RETRY_AFTER_MINUTES));
    }

    /**
     * Whether the deadline for giving up on this Generation (ADR-0007) has passed.
     */
    public function isPastGiveUpDeadline(): bool
    {
        return $this->created_at?->addMinutes(self::GIVE_UP_AFTER_MINUTES)->isPast() === true;
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

    /*
     * A live provider image URL: Pixelforge's signed image URL is known and
     * hasn't expired yet. hasLiveProviderImageUrl() checks it on a loaded
     * Generation, withLiveProviderImageUrl() in a query; change both together.
     */

    public function hasLiveProviderImageUrl(): bool
    {
        return $this->provider_image_url !== null
            && $this->provider_image_url_expires_at?->isFuture() === true;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withLiveProviderImageUrl(Builder $query): void
    {
        $query->whereNotNull('provider_image_url')
            ->where('provider_image_url_expires_at', '>', now());
    }

    public function lockRates(Rates $rates): void
    {
        $this->input_text_token_rate = $rates->inputTextToken->nanodollars();
        $this->input_image_token_rate = $rates->inputImageToken->nanodollars();
        $this->output_token_rate = $rates->outputToken->nanodollars();
        $this->reasoning_token_rate = $rates->reasoningToken->nanodollars();
    }
}
