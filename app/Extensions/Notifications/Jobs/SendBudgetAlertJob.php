<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Notifications\Jobs;

use FireflyIII\Extensions\Notifications\Models\PushSubscription;
use FireflyIII\Extensions\Notifications\Services\WebPushService;
use FireflyIII\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks all budgets for the given user and dispatches push notifications
 * for any that have crossed the configured spending threshold.
 */
class SendBudgetAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param User  $user
     * @param float $threshold Alert threshold as a fraction, e.g. 0.80 = 80%
     */
    public function __construct(
        private readonly User $user,
        private readonly float $threshold = 0.80,
    ) {}

    public function handle(WebPushService $pushService): void
    {
        $subscriptions = PushSubscription::where('user_id', $this->user->id)->get();

        if ($subscriptions->isEmpty()) {
            Log::debug('SendBudgetAlertJob: no push subscriptions for user.', ['user_id' => $this->user->id]);

            return;
        }

        // Query current spending per budget limit for this user.
        // We look for budget_limits that are currently active (start <= today <= end)
        // and compare the sum of attached transactions against the amount.
        $today = now()->toDateString();

        $budgetSpend = DB::table('budget_limits as bl')
            ->select([
                'bl.id as budget_limit_id',
                'b.name as budget_name',
                'bl.amount as limit_amount',
                DB::raw('COALESCE(SUM(ABS(t.amount)), 0) as spent'),
            ])
            ->join('budgets as b', 'b.id', '=', 'bl.budget_id')
            ->leftJoin('budget_transaction_journal as btj', 'btj.budget_id', '=', 'b.id')
            ->leftJoin('transaction_journals as tj', static function ($join) use ($today): void {
                $join->on('tj.id', '=', 'btj.transaction_journal_id')
                    ->whereNull('tj.deleted_at')
                    ->whereRaw("tj.date BETWEEN bl.start_date AND bl.end_date");
            })
            ->leftJoin('transactions as t', static function ($join): void {
                $join->on('t.transaction_journal_id', '=', 'tj.id')
                    ->where('t.amount', '<', 0);
            })
            ->where('b.user_id', $this->user->id)
            ->where('bl.start_date', '<=', $today)
            ->where('bl.end_date', '>=', $today)
            ->groupBy('bl.id', 'b.name', 'bl.amount')
            ->get();

        foreach ($budgetSpend as $row) {
            $shouldAlert = $pushService->shouldSendBudgetAlert(
                (string) $row->spent,
                (string) $row->limit_amount,
                $this->threshold
            );

            if (!$shouldAlert) {
                continue;
            }

            $pct     = 0 < (float) $row->limit_amount
                ? number_format(((float) $row->spent / (float) $row->limit_amount) * 100, 0)
                : '0';
            $title   = 'Budget Alert: ' . $row->budget_name;
            $body    = sprintf(
                'You have spent %s%% of your "%s" budget.',
                $pct,
                $row->budget_name
            );
            $data    = [
                'budget_limit_id' => $row->budget_limit_id,
                'spent'           => (string) $row->spent,
                'limit'           => (string) $row->limit_amount,
                'pct'             => $pct,
                'url'             => '/budgets',
            ];

            foreach ($subscriptions as $subscription) {
                /** @var PushSubscription $subscription */
                $pushService->send($subscription, $title, $body, $data);
            }
        }
    }
}
