<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Webhook Event as Pixelforge delivered it, kept for audit. The unique
 * `event_id` is what makes a redelivered event a no-op.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property string $payload
 * @property int|null $delivery_attempt
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property-read Generation|null $generation
 */
#[Fillable(['event_id', 'type', 'payload', 'delivery_attempt', 'received_at'])]
class WebhookEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_attempt' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
