<?php

declare(strict_types=1);

namespace FireflyIII\Extensions\Tax\Repositories;

use Carbon\Carbon;
use FireflyIII\Extensions\Tax\Models\TaxDeductibleTag;
use FireflyIII\Extensions\Tax\Models\TaxProfile;
use FireflyIII\Models\Tag;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxRepository implements TaxRepositoryInterface
{
    private User $user;

    public function setUser(null|Authenticatable|User $user): void
    {
        /** @var User $user */
        $this->user = $user;
    }

    public function createProfile(array $data): TaxProfile
    {
        return TaxProfile::create([
            'user_id'  => $this->user->id,
            'name'     => $data['name'],
            'tax_year' => $data['tax_year'],
            'tax_rate' => $data['tax_rate'] ?? 0.00,
            'notes'    => $data['notes'] ?? null,
        ]);
    }

    public function findProfile(int $profileId): ?TaxProfile
    {
        return TaxProfile::where('id', $profileId)
            ->where('user_id', $this->user->id)
            ->first();
    }

    public function getProfiles(): Collection
    {
        return TaxProfile::where('user_id', $this->user->id)
            ->orderBy('tax_year', 'desc')
            ->get();
    }

    public function deleteProfile(TaxProfile $profile): void
    {
        Log::debug(sprintf('Soft-deleting TaxProfile #%d for user #%d', $profile->id, $this->user->id));
        $profile->delete();
    }

    public function linkTag(TaxProfile $profile, Tag $tag): TaxDeductibleTag
    {
        /** @var null|TaxDeductibleTag $existing */
        $existing = TaxDeductibleTag::where('tax_profile_id', $profile->id)
            ->where('tag_id', $tag->id)
            ->first();

        if (null !== $existing) {
            return $existing;
        }

        return TaxDeductibleTag::create([
            'tax_profile_id' => $profile->id,
            'tag_id'         => $tag->id,
        ]);
    }

    public function unlinkTag(TaxProfile $profile, Tag $tag): void
    {
        TaxDeductibleTag::where('tax_profile_id', $profile->id)
            ->where('tag_id', $tag->id)
            ->delete();
    }

    public function getLinkedTags(TaxProfile $profile): Collection
    {
        return TaxDeductibleTag::where('tax_profile_id', $profile->id)
            ->with('tag')
            ->get();
    }

    /**
     * @return array<int, array{amount: string, category: string|null, date: string, description: string}>
     */
    public function getDeductibleJournals(TaxProfile $profile, Carbon $start, Carbon $end): array
    {
        // Collect the tag IDs linked to this profile
        $tagIds = TaxDeductibleTag::where('tax_profile_id', $profile->id)
            ->pluck('tag_id')
            ->toArray();

        if (0 === count($tagIds)) {
            return [];
        }

        // Query transaction journals that carry any of the deductible tags.
        // We join tag_transaction_journal and pull the negative (withdrawal)
        // transaction amount plus the optional category name.
        $rows = DB::table('transaction_journals as tj')
            ->select([
                'tj.description',
                'tj.date',
                DB::raw('SUM(t.amount) as amount'),
                'c.name as category',
            ])
            ->join('tag_transaction_journal as ttj', 'ttj.transaction_journal_id', '=', 'tj.id')
            ->join('transactions as t', static function ($join): void {
                $join->on('t.transaction_journal_id', '=', 'tj.id')
                    ->where('t.amount', '<', 0);
            })
            ->leftJoin('category_transaction_journal as ctj', 'ctj.transaction_journal_id', '=', 'tj.id')
            ->leftJoin('categories as c', 'c.id', '=', 'ctj.category_id')
            ->where('tj.user_id', $this->user->id)
            ->whereNull('tj.deleted_at')
            ->whereIn('ttj.tag_id', $tagIds)
            ->whereBetween('tj.date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->groupBy('tj.id', 'tj.description', 'tj.date', 'c.name')
            ->orderBy('tj.date')
            ->get();

        return $rows->map(static fn ($row): array => [
            'amount'      => (string) $row->amount,
            'category'    => $row->category,
            'date'        => Carbon::parse($row->date)->format('Y-m-d'),
            'description' => (string) $row->description,
        ])->toArray();
    }
}
