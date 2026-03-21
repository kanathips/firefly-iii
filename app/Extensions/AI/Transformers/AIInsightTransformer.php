<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\AI\Transformers;

/**
 * Transforms the raw insight array produced by AIInsightService into the
 * JSON:API-compatible envelope used by the AIController.
 */
class AIInsightTransformer
{
    /**
     * @param array{
     *   period_start: string,
     *   period_end: string,
     *   total_spent: float,
     *   top_categories: array<int, array{category: string, amount: float, percentage: float}>,
     *   daily_average: float,
     *   transaction_count: int
     * } $insights
     *
     * @return array{type: string, attributes: array<string, mixed>}
     */
    public function transform(array $insights): array
    {
        return [
            'type'       => 'ai_insight',
            'attributes' => [
                'period_start'      => $insights['period_start'],
                'period_end'        => $insights['period_end'],
                'total_spent'       => $insights['total_spent'],
                'top_categories'    => $insights['top_categories'],
                'daily_average'     => $insights['daily_average'],
                'transaction_count' => $insights['transaction_count'],
            ],
        ];
    }
}
