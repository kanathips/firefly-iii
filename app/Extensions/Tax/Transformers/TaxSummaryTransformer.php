<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Transformers;

use FireflyIII\Extensions\Tax\Models\TaxProfile;

/**
 * Transforms a TaxProfile Eloquent model into a JSON:API-compatible array
 * for use in API responses.
 */
class TaxSummaryTransformer
{
    /**
     * Transform a single TaxProfile into a resource representation.
     *
     * @return array{
     *   id: int,
     *   attributes: array{
     *     name: string,
     *     tax_year: int,
     *     tax_rate: float,
     *     notes: null|string,
     *     created_at: null|string,
     *     updated_at: null|string,
     *   }
     * }
     */
    public function transform(TaxProfile $profile): array
    {
        return [
            'id'         => $profile->id,
            'attributes' => [
                'name'       => $profile->name,
                'tax_year'   => $profile->tax_year,
                'tax_rate'   => (float) $profile->tax_rate,
                'notes'      => $profile->notes,
                'created_at' => $profile->created_at?->toIso8601String(),
                'updated_at' => $profile->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Transform a summary payload (from TaxCalculationService::buildSummary) into
     * an API response structure.
     *
     * @param  array{
     *   profile_id: int,
     *   start: string,
     *   end: string,
     *   total_deductible: float,
     *   by_category: array<string, float>,
     *   by_period: array<string, array{total: float, journals: array}>
     * } $summary
     *
     * @return array{
     *   data: array{
     *     profile_id: int,
     *     start: string,
     *     end: string,
     *     total_deductible: float,
     *     by_category: array<string, float>,
     *     by_period: array<string, array{total: float, journals: array}>
     *   }
     * }
     */
    public function transformSummary(array $summary): array
    {
        return [
            'data' => [
                'profile_id'       => $summary['profile_id'],
                'start'            => $summary['start'],
                'end'              => $summary['end'],
                'total_deductible' => $summary['total_deductible'],
                'by_category'      => $summary['by_category'],
                'by_period'        => $summary['by_period'],
            ],
        ];
    }
}
