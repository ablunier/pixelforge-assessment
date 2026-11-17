<?php

namespace App\Actions;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSnapshot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Records a verified Webhook Event and applies it to its Generation, once.
 *
 * Storing the event and applying it share one transaction: a duplicate event
 * id (a retry, a resend or a `[duplicate]` delivery) finds the stored row and
 * does nothing, and if applying fails the row is rolled back so Pixelforge's
 * retry is processed rather than mistaken for a duplicate. Ordering is left
 * to GenerationLifecycle, which never moves a Generation backwards.
 */
final readonly class ReceiveWebhookEvent
{
    public function __construct(private GenerationLifecycle $lifecycle) {}

    /**
     * @param  string  $payload  the raw, verified request body
     *
     * @throws InvalidArgumentException when the payload isn't a Webhook Event
     */
    public function handle(string $payload, ?int $deliveryAttempt): void
    {
        [$eventId, $type, $snapshot] = $this->parse($payload);

        DB::transaction(function () use ($eventId, $type, $snapshot, $payload, $deliveryAttempt): void {
            $webhookEvent = WebhookEvent::query()->createOrFirst(['event_id' => $eventId], [
                'type' => $type,
                'payload' => $payload,
                'delivery_attempt' => $deliveryAttempt,
                'received_at' => now(),
            ]);

            if (! $webhookEvent->wasRecentlyCreated) {
                return;
            }

            $generation = $snapshot === null ? null : $this->generationFor($snapshot);

            // Unmatched (e.g. sent for another environment's data): kept for audit only.
            if ($snapshot !== null && $generation !== null) {
                $this->lifecycle->apply($generation, $snapshot);
                $webhookEvent->generation()->associate($generation);
            }

            $webhookEvent->processed_at = now();
            $webhookEvent->save();
        });
    }

    /**
     * The event id, its type, and for `generation.*` events the Generation
     * snapshot in `data.object`. `ping` (and any type added later) has none:
     * it's only acknowledged and kept.
     *
     * @return array{string, string, ?GenerationSnapshot}
     */
    private function parse(string $payload): array
    {
        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Webhook Event is not JSON.', previous: $exception);
        }

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new InvalidArgumentException('Webhook Event has no id or type.');
        }

        if (! str_starts_with($event['type'], 'generation.')) {
            return [$event['id'], $event['type'], null];
        }

        $object = $event['data']['object'] ?? null;

        if (! is_array($object)) {
            throw new InvalidArgumentException('Generation Webhook Event has no data.object.');
        }

        /** @var array<string, mixed> $object */
        return [$event['id'], $event['type'], GenerationSnapshot::fromPixelforge($object)];
    }

    /**
     * By the local id in metadata first: an event can arrive before the
     * create response, while the Generation has no `gen_` id yet (ADR-0003).
     */
    private function generationFor(GenerationSnapshot $snapshot): ?Generation
    {
        $byLocalId = $snapshot->generationId === null ? null : Generation::query()->find($snapshot->generationId);

        return $byLocalId ?? Generation::query()->where('pixelforge_id', $snapshot->pixelforgeId)->first();
    }
}
