<?php

namespace App\Models;

use App\Enums\LedgerEntryKind;
use App\Money\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Generation> $generations
 * @property-read Collection<int, LedgerEntry> $ledgerEntries
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Not an admin unless made one, matching the column's default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The Credits this user holds: Top-ups minus Charges, derived from the ledger (ADR-0002).
     */
    public function balance(): Money
    {
        return $this->ledgerTotal(LedgerEntryKind::TopUp)->minus($this->ledgerTotal(LedgerEntryKind::Charge));
    }

    /**
     * What this user can still commit to new Generations: the Balance minus
     * active Holds, the part of each Hold not yet settled by its Charge or
     * Hold release (ADR-0002).
     */
    public function availableBalance(): Money
    {
        $activeHolds = $this->ledgerTotal(LedgerEntryKind::Hold)
            ->minus($this->ledgerTotal(LedgerEntryKind::Charge))
            ->minus($this->ledgerTotal(LedgerEntryKind::HoldRelease));

        return $this->balance()->minus($activeHolds);
    }

    private function ledgerTotal(LedgerEntryKind $kind): Money
    {
        return Money::ofNanodollars((int) $this->ledgerEntries()->where('kind', $kind)->sum('amount'));
    }
}
