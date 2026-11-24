<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryKind;
use App\Models\Generation;
use App\Models\User;
use App\Money\Money;
use App\Pixelforge\PixelforgeBilled;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\PixelforgeUnreachable;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Reports\AdminReport;
use App\Reports\ComparedTotals;
use App\Reports\DailyRow;
use App\Reports\DateRange;
use App\Reports\GenerationTotals;
use App\Reports\UserRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * Compile the admin report for a date range from local data: the succeeded
 * Generations, counted on the UTC day they completed (as Pixelforge bills),
 * with their Provider Costs and Charges. A Generation from before Balances
 * existed has no Charge and counts as zero user spend.
 *
 * Each day's Provider Cost is set beside what Pixelforge billed that day. If
 * Pixelforge's usage can't be had, the report still shows the local figures.
 */
class CompileAdminReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    public function handle(DateRange $range): AdminReport
    {
        $buckets = $this->totalsByDayAndUser($range);

        $byDay = [];
        $byUser = [];
        foreach ($buckets as ['day' => $day, 'userId' => $userId, 'totals' => $totals]) {
            $byDay[$day] = ($byDay[$day] ?? GenerationTotals::none())->plus($totals);
            $byUser[$userId] = ($byUser[$userId] ?? GenerationTotals::none())->plus($totals);
        }

        $billed = $this->billed($range);

        $days = array_map(
            fn (CarbonImmutable $day): DailyRow => new DailyRow($day, new ComparedTotals($byDay[$day->toDateString()] ?? GenerationTotals::none(), $billed?->on($day))),
            $range->days(),
        );

        return new AdminReport($range, $days, $this->userRows($byUser), $billed);
    }

    /**
     * What Pixelforge billed over the range, or null when it can't be had
     * (rate limited, overloaded, our API key refused, unreachable or unreadable).
     */
    private function billed(DateRange $range): ?PixelforgeBilled
    {
        try {
            return $this->pixelforge->billed($range->from, $range->to);
        } catch (PixelforgeError|PixelforgeUnreachable|UnexpectedPixelforgeResponse $failure) {
            report($failure);

            return null;
        }
    }

    /**
     * One bucket per UTC completion day and user with succeeded Generations.
     * The ledger allows at most one Charge per Generation, so the join never
     * counts a Generation twice.
     *
     * @return Collection<int, array{day: string, userId: int, totals: GenerationTotals}>
     */
    private function totalsByDayAndUser(DateRange $range): Collection
    {
        return Generation::query()
            ->toBase()
            ->leftJoin('ledger_entries as charges', fn (JoinClause $join) => $join
                ->on('charges.generation_id', '=', 'generations.id')
                ->where('charges.kind', LedgerEntryKind::Charge->value))
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->where('generations.completed_at', '>=', $range->from)
            ->where('generations.completed_at', '<', $range->endExclusive())
            // Timestamps are stored in UTC, so the date part is the UTC day.
            ->selectRaw('cast(generations.completed_at as date) as day')
            ->addSelect('generations.user_id')
            ->selectRaw('count(*) as generations')
            ->selectRaw('coalesce(sum(generations.provider_cost), 0) as provider_cost')
            ->selectRaw('coalesce(sum(charges.amount), 0) as charges')
            ->groupByRaw('cast(generations.completed_at as date), generations.user_id')
            ->get()
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                'userId' => (int) $row->user_id,
                'totals' => new GenerationTotals(
                    (int) $row->generations,
                    Money::ofNanodollars((int) $row->charges),
                    Money::ofNanodollars((int) $row->provider_cost),
                ),
            ]);
    }

    /**
     * The users with activity, biggest spenders first, including those who
     * have since deleted their account.
     *
     * @param  array<int, GenerationTotals>  $byUser
     * @return list<UserRow>
     */
    private function userRows(array $byUser): array
    {
        $users = User::withTrashed()->whereKey(array_keys($byUser))->get()->keyBy('id');

        $rows = array_map(
            fn (int $userId, GenerationTotals $totals): UserRow => new UserRow($users[$userId], $totals),
            array_keys($byUser),
            $byUser,
        );

        usort($rows, fn (UserRow $a, UserRow $b): int => [$b->totals->charges->nanodollars(), $a->user->id]
            <=> [$a->totals->charges->nanodollars(), $b->user->id]);

        return $rows;
    }
}
