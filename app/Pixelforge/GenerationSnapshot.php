<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Pricing\Rates;
use App\Pricing\Usage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pixelforge's view of a Generation at one moment: from a create response, a
 * poll or a Webhook Event's `data.object`.
 */
final readonly class GenerationSnapshot
{
    public function __construct(
        /** Pixelforge's `gen_` id. */
        public string $pixelforgeId,
        public GenerationStatus $status,
        /** The local Generation id echoed back in `metadata.generation_id`, if any. */
        public ?string $generationId,
        public Rates $rates,
        public ?Usage $usage,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * Build from a Pixelforge Generation object.
     *
     * @param  array<string, mixed>  $generation
     */
    public static function fromPixelforge(array $generation): self
    {
        $id = $generation['id'] ?? null;
        $status = GenerationStatus::tryFrom(is_string($generation['status'] ?? null) ? $generation['status'] : '');

        if (! is_string($id) || $status === null || $status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('Not a Pixelforge Generation object.');
        }

        $metadata = is_array($generation['metadata'] ?? null) ? $generation['metadata'] : [];
        $error = is_array($generation['error'] ?? null) ? $generation['error'] : [];

        return new self(
            pixelforgeId: $id,
            status: $status,
            generationId: self::string($metadata, 'generation_id'),
            rates: Rates::fromPixelforge(is_array($generation['rates'] ?? null) ? $generation['rates'] : []),
            usage: is_array($generation['usage'] ?? null) ? Usage::fromPixelforge($generation['usage']) : null,
            errorCode: self::string($error, 'code'),
            errorMessage: self::string($error, 'message'),
            imageUrl: self::string($generation, 'image_url'),
            imageUrlExpiresAt: self::time($generation, 'image_url_expires_at'),
            createdAt: self::time($generation, 'created_at'),
            startedAt: self::time($generation, 'started_at'),
            completedAt: self::time($generation, 'completed_at'),
        );
    }

    /**
     * @param  array<mixed>  $object
     */
    private static function string(array $object, string $key): ?string
    {
        return is_string($object[$key] ?? null) ? $object[$key] : null;
    }

    /**
     * @param  array<mixed>  $object
     */
    private static function time(array $object, string $key): ?CarbonImmutable
    {
        $value = self::string($object, $key);

        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
