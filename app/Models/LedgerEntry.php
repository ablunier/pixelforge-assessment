<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\LedgerEntryKind;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /** How many entries one page of the ledger history lists. */
    public const int HISTORY_PAGE_SIZE = 50;

    /**
     * Append one entry to user `$userId`'s ledger: a Top-up, or a Hold, Hold
     * release or Charge of Generation `$generationId`. The only way entries
     * are written.
     */
    public static function record(int $userId, LedgerEntryKind $kind, Money $amount, ?string $generationId = null): self
    {
        $entry = new self(['generation_id' => $generationId, 'kind' => $kind, 'amount' => $amount]);
        $entry->user_id = $userId;
        $entry->save();

        return $entry;
    }

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
     * Entries as the ledger history lists them: newest first, each with the
     * id and prompt of its Generation.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function history(Builder $query): void
    {
        $query->with('generation:id,prompt')->latest()->orderByDesc('id');
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
