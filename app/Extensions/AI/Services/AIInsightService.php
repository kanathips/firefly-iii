<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Services;

use Carbon\Carbon;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Analyses a user's transaction history and returns spending pattern insights.
 *
 * This service queries the Firefly III transaction tables directly and does NOT
 * call the external AI API — it computes structured insights locally which can
 * then be surfaced in the dashboard or forwarded to an AI provider for a
 * natural-language summary.
 */
class AIInsightService
{
    private const LOOKBACK_DAYS     = 30;
    private const TOP_CATEGORY_LIMIT = 5;

    /**
     * Build a spending insight summary for the given user over the last 30 days.
     *
     * @return array{
     *   period_start: string,
     *   period_end: string,
     *   total_spent: float,
     *   top_categories: array<int, array{category: string, amount: float, percentage: float}>,
     *   daily_average: float,
     *   transaction_count: int
     * }
     */
    public function buildInsights(User $user): array
    {
        $end   = Carbon::today();
        $start = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        Log::debug(sprintf('[AI] Building insights for user #%d from %s to %s', $user->id, $start->toDateString(), $end->toDateString()));

        $totals    = $this->querySpendingTotals($user->id, $start, $end);
        $byCategory = $this->queryByCategory($user->id, $start, $end);
        $count     = $this->queryTransactionCount($user->id, $start, $end);

        $totalSpent  = $totals['total'] ?? 0.0;
        $dailyAvg    = self::LOOKBACK_DAYS > 0 ? round($totalSpent / self::LOOKBACK_DAYS, 2) : 0.0;

        $topCategories = $this->buildTopCategories($byCategory, $totalSpent);

        return [
            'period_start'      => $start->toDateString(),
            'period_end'        => $end->toDateString(),
            'total_spent'       => round($totalSpent, 2),
            'top_categories'    => $topCategories,
            'daily_average'     => $dailyAvg,
            'transaction_count' => $count,
        ];
    }

    private function querySpendingTotals(int $userId, Carbon $start, Carbon $end): array
    {
        $row = DB::table('transaction_journals as tj')
            ->select(DB::raw('ABS(SUM(t.amount)) as total'))
            ->join('transactions as t', static function ($join): void {
                $join->on('t.transaction_journal_id', '=', 'tj.id')
                    ->where('t.amount', '<', 0);
            })
            ->where('tj.user_id', $userId)
            ->whereNull('tj.deleted_at')
            ->whereBetween('tj.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->first();

        return ['total' => (float) ($row->total ?? 0.0)];
    }

    private function queryByCategory(int $userId, Carbon $start, Carbon $end): array
    {
        return DB::table('transaction_journals as tj')
            ->select([
                DB::raw('COALESCE(c.name, "(none)") as category'),
                DB::raw('ABS(SUM(t.amount)) as amount'),
            ])
            ->join('transactions as t', static function ($join): void {
                $join->on('t.transaction_journal_id', '=', 'tj.id')
                    ->where('t.amount', '<', 0);
            })
            ->leftJoin('category_transaction_journal as ctj', 'ctj.transaction_journal_id', '=', 'tj.id')
            ->leftJoin('categories as c', 'c.id', '=', 'ctj.category_id')
            ->where('tj.user_id', $userId)
            ->whereNull('tj.deleted_at')
            ->whereBetween('tj.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->groupBy('c.name')
            ->orderByDesc('amount')
            ->limit(self::TOP_CATEGORY_LIMIT)
            ->get()
            ->toArray();
    }

    private function queryTransactionCount(int $userId, Carbon $start, Carbon $end): int
    {
        return (int) DB::table('transaction_journals')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->count();
    }

    private function buildTopCategories(array $rows, float $totalSpent): array
    {
        return array_map(static function (object $row) use ($totalSpent): array {
            $amount     = (float) $row->amount;
            $percentage = $totalSpent > 0.0 ? round(($amount / $totalSpent) * 100, 1) : 0.0;

            return [
                'category'   => (string) $row->category,
                'amount'     => round($amount, 2),
                'percentage' => $percentage,
            ];
        }, $rows);
    }
}
