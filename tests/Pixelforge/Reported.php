<?php

namespace Tests\Pixelforge;

use App\Enums\GenerationStatus;
use App\Pixelforge\GenerationSnapshot;
use App\Pricing\Rates;
use App\Pricing\Usage;
use Carbon\CarbonImmutable;

/**
 * What Pixelforge reports a Generation as, on the docs' lighthouse timeline:
 * created 2026-11-04T14:03:11Z, started 14:03:13Z, completed 14:03:29Z.
 */
final readonly class Reported
{
    /** The docs' signed image URL. */
    public const string IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc';

    public const string IMAGE_URL_EXPIRES_AT = '2026-11-05T14:03:29Z';

    private function __construct(
        public GenerationStatus $status,
        /** The rates Pixelforge locks the Generation at, when not the ones it already has. */
        public ?Rates $lockedRates = null,
        public ?Usage $usage = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $imageUrl = null,
        public ?string $imageUrlExpiresAt = null,
    ) {}

    public static function queued(?Rates $lockedRates = null): self
    {
        return new self(GenerationStatus::Queued, $lockedRates);
    }

    public static function processing(): self
    {
        return new self(GenerationStatus::Processing);
    }

    /**
     * Succeeded with the docs' usage (12 text, 765 image, 6,450 output and
     * 3,184 reasoning tokens) unless told otherwise.
     */
    public static function succeeded(?Usage $usage = null, string $imageUrl = self::IMAGE_URL, string $imageUrlExpiresAt = self::IMAGE_URL_EXPIRES_AT): self
    {
        return new self(
            GenerationStatus::Succeeded,
            usage: $usage ?? new Usage(12, 765, 6_450, 3_184),
            imageUrl: $imageUrl,
            imageUrlExpiresAt: $imageUrlExpiresAt,
        );
    }

    public static function failed(string $errorCode = 'generation_failed', string $errorMessage = "The model couldn't produce an image for this prompt."): self
    {
        return new self(GenerationStatus::Failed, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    /**
     * Pixelforge's view of Generation `$pixelforgeId` as reported, locked at
     * `$rates` unless this report locks others.
     */
    public function snapshot(string $pixelforgeId, ?string $generationId = null, ?Rates $rates = null): GenerationSnapshot
    {
        $started = in_array($this->status, [GenerationStatus::Processing, GenerationStatus::Succeeded, GenerationStatus::Failed], true);

        return new GenerationSnapshot(
            pixelforgeId: $pixelforgeId,
            status: $this->status,
            generationId: $generationId,
            rates: $this->lockedRates ?? $rates ?? InMemoryPixelforgeClient::publishedRates(),
            usage: $this->usage,
            errorCode: $this->errorCode,
            errorMessage: $this->errorMessage,
            imageUrl: $this->imageUrl,
            imageUrlExpiresAt: $this->imageUrlExpiresAt === null ? null : CarbonImmutable::parse($this->imageUrlExpiresAt),
            createdAt: CarbonImmutable::parse('2026-11-04T14:03:11Z'),
            startedAt: $started ? CarbonImmutable::parse('2026-11-04T14:03:13Z') : null,
            completedAt: $this->status->isFinal() ? CarbonImmutable::parse('2026-11-04T14:03:29Z') : null,
        );
    }
}
