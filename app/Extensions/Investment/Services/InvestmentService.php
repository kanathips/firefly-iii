<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Services;

use FireflyIII\Extensions\Investment\Models\InvestmentPosition;
use FireflyIII\Extensions\Investment\Repositories\InvestmentRepositoryInterface;
use FireflyIII\User;

/**
 * Business logic for investment portfolio calculations.
 *
 * All monetary values are kept as strings and calculated with bcmath
 * (bcscale(12) is set globally in bootstrap/app.php) to avoid
 * floating-point drift.  Results are returned as 2-decimal strings.
 */
class InvestmentService
{
    public function __construct(private readonly InvestmentRepositoryInterface $repository) {}

    // ─── Portfolio Total ─────────────────────────────────────────────────────

    /**
     * Return the total current portfolio value as a 2-decimal string.
     * Positions without a latest snapshot contribute 0.
     */
    public function getPortfolioTotal(User $user): string
    {
        $positions = $this->repository->findAllForUser($user);
        $total     = '0';

        foreach ($positions as $position) {
            /** @var InvestmentPosition $position */
            $snapshot = $position->latestSnapshot;
            if (null === $snapshot) {
                continue;
            }
            $positionValue = bcmul((string) $position->quantity, (string) $snapshot->current_price);
            $total         = bcadd($total, $positionValue);
        }

        return $this->format($total);
    }

    // ─── Unrealized Gains ────────────────────────────────────────────────────

    /**
     * Calculate unrealized gain/loss for a single position.
     *
     * Formula: (current_price - avg_cost) * quantity
     */
    public function calculateUnrealizedGain(string $quantity, string $avgCost, string $currentPrice): string
    {
        $priceDiff = bcsub($currentPrice, $avgCost);
        $gain      = bcmul($priceDiff, $quantity);

        return $this->format($gain);
    }

    // ─── Allocation Breakdown ────────────────────────────────────────────────

    /**
     * Return an associative array of symbol => percentage-of-portfolio.
     * Percentages are 2-decimal strings.  Returns [] when portfolio is empty.
     *
     * @return array<string, string>
     */
    public function getAllocationBreakdown(User $user): array
    {
        $positions = $this->repository->findAllForUser($user);
        if ($positions->isEmpty()) {
            return [];
        }

        // First pass: compute per-symbol value
        $values = [];
        $total  = '0';

        foreach ($positions as $position) {
            /** @var InvestmentPosition $position */
            $snapshot = $position->latestSnapshot;
            if (null === $snapshot) {
                continue;
            }
            $value               = bcmul((string) $position->quantity, (string) $snapshot->current_price);
            $symbol              = (string) $position->symbol;
            $values[$symbol]     = bcadd($values[$symbol] ?? '0', $value);
            $total               = bcadd($total, $value);
        }

        if (0 === bccomp($total, '0')) {
            return [];
        }

        // Second pass: compute percentages
        $breakdown = [];
        foreach ($values as $symbol => $value) {
            $pct              = bcmul(bcdiv($value, $total), '100');
            $breakdown[$symbol] = $this->format($pct);
        }

        return $breakdown;
    }

    // ─── Portfolio Summary ───────────────────────────────────────────────────

    /**
     * Build a complete portfolio summary for the given user.
     *
     * @return array{
     *   total_value: string,
     *   total_cost: string,
     *   unrealized_gain: string,
     *   unrealized_gain_pct: string,
     *   allocation: array<string, string>,
     *   positions_count: int,
     * }
     */
    public function getPortfolioSummary(User $user): array
    {
        $positions    = $this->repository->findAllForUser($user);
        $totalValue   = '0';
        $totalCost    = '0';

        foreach ($positions as $position) {
            /** @var InvestmentPosition $position */
            $snapshot = $position->latestSnapshot;

            // Current value contribution
            if (null !== $snapshot) {
                $positionValue = bcmul((string) $position->quantity, (string) $snapshot->current_price);
                $totalValue    = bcadd($totalValue, $positionValue);
            }

            // Cost basis contribution
            $costBasis  = bcmul((string) $position->quantity, (string) $position->avg_cost);
            $totalCost  = bcadd($totalCost, $costBasis);
        }

        $unrealizedGain = bcsub($totalValue, $totalCost);

        // Avoid division by zero
        if (0 === bccomp($totalCost, '0')) {
            $gainPct = '0.00';
        } else {
            $gainPct = $this->format(bcmul(bcdiv($unrealizedGain, $totalCost), '100'));
        }

        return [
            'total_value'        => $this->format($totalValue),
            'total_cost'         => $this->format($totalCost),
            'unrealized_gain'    => $this->format($unrealizedGain),
            'unrealized_gain_pct'=> $gainPct,
            'allocation'         => $this->getAllocationBreakdown($user),
            'positions_count'    => $positions->count(),
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Round a bcmath result to 2 decimal places and format as string.
     */
    private function format(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
