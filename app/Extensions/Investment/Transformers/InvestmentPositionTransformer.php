<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Investment\Transformers;

use FireflyIII\Extensions\Investment\Models\InvestmentPosition;

/**
 * Transforms an InvestmentPosition (with optional latestSnapshot) into a
 * JSON:API-flavoured attributes array.
 */
class InvestmentPositionTransformer
{
    public function transform(InvestmentPosition $position): array
    {
        $snapshot      = $position->latestSnapshot;
        $currentPrice  = $snapshot ? (string) $snapshot->current_price : null;
        $snapshotDate  = $snapshot ? $snapshot->snapshot_date?->format('Y-m-d') : null;

        // Unrealized gain = (current_price - avg_cost) * quantity
        $unrealizedGain = null;
        if (null !== $currentPrice) {
            $priceDiff      = bcsub($currentPrice, (string) $position->avg_cost);
            $unrealizedGain = number_format(
                (float) bcmul($priceDiff, (string) $position->quantity),
                2,
                '.',
                ''
            );
        }

        return [
            'id'             => $position->id,
            'type'           => 'investment_position',
            'attributes'     => [
                'symbol'          => $position->symbol,
                'quantity'        => number_format((float) $position->quantity, 2, '.', ''),
                'avg_cost'        => number_format((float) $position->avg_cost, 2, '.', ''),
                'current_price'   => null !== $currentPrice ? number_format((float) $currentPrice, 2, '.', '') : null,
                'snapshot_date'   => $snapshotDate,
                'unrealized_gain' => $unrealizedGain,
                'notes'           => $position->notes,
                'account_id'      => $position->account_id,
                'created_at'      => $position->created_at?->toISOString(),
                'updated_at'      => $position->updated_at?->toISOString(),
            ],
        ];
    }
}
