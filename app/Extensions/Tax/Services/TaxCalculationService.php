<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Services;

use Carbon\Carbon;
use FireflyIII\Extensions\Tax\Repositories\TaxRepositoryInterface;
use InvalidArgumentException;

/**
 * Service that computes tax deductible totals from transaction journals.
 *
 * All amounts are treated as absolute values (withdrawals are stored negative).
 */
class TaxCalculationService
{
    public function __construct(private readonly TaxRepositoryInterface $repository) {}

    /**
     * Compute deductible totals for the given profile and date range.
     *
     * Returns:
     *   [
     *     'total'       => float,
     *     'by_category' => ['CategoryName' => float, '(none)' => float, ...],
     *   ]
     *
     * @return array{total: float, by_category: array<string, float>}
     */
    public function computeDeductibleTotals(int $profileId, Carbon $start, Carbon $end): array
    {
        $journals   = $this->repository->getDeductibleJournals($profileId, $start, $end);

        $total      = 0.0;
        $byCategory = [];

        foreach ($journals as $journal) {
            $amount   = abs((float) $journal['amount']);
            $category = $journal['category'] ?? null;
            $key      = (null === $category || '' === $category) ? '(none)' : $category;

            $total               += $amount;
            $byCategory[$key]     = ($byCategory[$key] ?? 0.0) + $amount;
        }

        return [
            'total'       => $total,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Group a flat array of journal rows by a time period.
     *
     * Supported periods: 'month', 'year'.
     *
     * Each group contains:
     *   ['total' => float, 'journals' => array]
     *
     * @param  array<int, array{amount: string, category: string|null, date: string}>  $journals
     * @param  string  $period  'month' or 'year'
     * @return array<string, array{total: float, journals: array}>
     *
     * @throws InvalidArgumentException  when an unsupported period is supplied
     */
    public function groupByPeriod(array $journals, string $period): array
    {
        if (!in_array($period, ['month', 'year'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported period "%s". Allowed values: month, year.',
                $period
            ));
        }

        if ([] === $journals) {
            return [];
        }

        $groups = [];

        foreach ($journals as $journal) {
            $date   = Carbon::parse($journal['date']);
            $key    = 'month' === $period ? $date->format('Y-m') : $date->format('Y');
            $amount = abs((float) $journal['amount']);

            if (!array_key_exists($key, $groups)) {
                $groups[$key] = ['total' => 0.0, 'journals' => []];
            }

            $groups[$key]['total']      += $amount;
            $groups[$key]['journals'][]  = $journal;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Apply a percentage tax rate to a deductible amount.
     *
     * @param  float  $amount   the deductible amount (positive)
     * @param  float  $rate     the tax rate as a percentage, e.g. 20.0 for 20%
     * @return float            the tax amount
     *
     * @throws InvalidArgumentException  when $rate is outside [0, 100]
     */
    public function computeTaxRate(float $amount, float $rate): float
    {
        if ($rate < 0.0 || $rate > 100.0) {
            throw new InvalidArgumentException(sprintf(
                'Tax rate must be between 0 and 100, got %.2f.',
                $rate
            ));
        }

        if (0.0 === $amount || 0.0 === $rate) {
            return 0.0;
        }

        return round($amount * $rate / 100, 10);
    }

    /**
     * Build a full summary combining totals, category breakdown, and period grouping.
     *
     * The repository is queried exactly once; all aggregations are derived from
     * the same journal slice to avoid redundant database calls.
     *
     * @return array{
     *   profile_id: int,
     *   start: string,
     *   end: string,
     *   total_deductible: float,
     *   by_category: array<string, float>,
     *   by_period: array<string, array{total: float, journals: array}>
     * }
     */
    public function buildSummary(int $profileId, Carbon $start, Carbon $end, string $period): array
    {
        $journals   = $this->repository->getDeductibleJournals($profileId, $start, $end);

        // Compute category totals directly from the pre-fetched journals array
        $total      = 0.0;
        $byCategory = [];

        foreach ($journals as $journal) {
            $amount   = abs((float) $journal['amount']);
            $category = $journal['category'] ?? null;
            $key      = (null === $category || '' === $category) ? '(none)' : $category;

            $total               += $amount;
            $byCategory[$key]     = ($byCategory[$key] ?? 0.0) + $amount;
        }

        $byPeriod = $this->groupByPeriod($journals, $period);

        return [
            'profile_id'       => $profileId,
            'start'            => $start->format('Y-m-d'),
            'end'              => $end->format('Y-m-d'),
            'total_deductible' => $total,
            'by_category'      => $byCategory,
            'by_period'        => $byPeriod,
        ];
    }
}
