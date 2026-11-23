<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\LedgerEntryKind;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the append-only ledger (ADR-0002): a Top-up, Hold, Hold release
 * or Charge. Never updated or deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property LedgerEntryKind $kind
 * @property Money $amount
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Generation|null $generation
 */
#[Fillable(['generation_id', 'kind', 'amount'])]
class LedgerEntry extends Model
{
    public const null UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Ledger entries are append-only and are never updated.'));
        static::deleting(fn (): never => throw new LogicException('Ledger entries are append-only and are never deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LedgerEntryKind::class,
            'amount' => AsMoney::class,
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
