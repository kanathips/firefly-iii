<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Transformers;

/**
 * Transforms a raw portfolio summary array (from InvestmentService) into
 * the response shape used by the API.
 *
 * @param array{
 *   total_value: string,
 *   total_cost: string,
 *   unrealized_gain: string,
 *   unrealized_gain_pct: string,
 *   allocation: array<string, string>,
 *   positions_count: int,
 * } $summary
 */
class PortfolioSummaryTransformer
{
    public function transform(array $summary): array
    {
        return [
            'total_value'         => $summary['total_value'],
            'total_cost'          => $summary['total_cost'],
            'unrealized_gain'     => $summary['unrealized_gain'],
            'unrealized_gain_pct' => $summary['unrealized_gain_pct'],
            'positions_count'     => $summary['positions_count'],
            'allocation'          => $summary['allocation'],
        ];
    }
}
