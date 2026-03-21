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

interface TaxRepositoryInterface
{
    public function setUser(null|Authenticatable|User $user): void;

    /**
     * Create a new TaxProfile for the current user.
     *
     * @param array{name: string, tax_year: int, tax_rate?: float, notes?: string} $data
     */
    public function createProfile(array $data): TaxProfile;

    /**
     * Find a TaxProfile by ID that belongs to the current user. Returns null
     * if not found or if it belongs to another user.
     */
    public function findProfile(int $profileId): ?TaxProfile;

    /**
     * Return all TaxProfiles for the current user (not soft-deleted).
     */
    public function getProfiles(): Collection;

    /**
     * Soft-delete a TaxProfile.
     */
    public function deleteProfile(TaxProfile $profile): void;

    /**
     * Link a Tag to a TaxProfile (idempotent – no error if already linked).
     */
    public function linkTag(TaxProfile $profile, Tag $tag): TaxDeductibleTag;

    /**
     * Remove the link between a Tag and a TaxProfile.
     */
    public function unlinkTag(TaxProfile $profile, Tag $tag): void;

    /**
     * Return all Tags currently linked to the given TaxProfile.
     */
    public function getLinkedTags(TaxProfile $profile): Collection;

    /**
     * Return a flat array of journal rows for all transactions tagged with any
     * of the deductible tags in the given profile, within the date range.
     *
     * Each row contains at minimum:
     *   - amount      (string, negative for withdrawals)
     *   - category    (string|null)
     *   - date        (string, Y-m-d)
     *   - description (string)
     *
     * @return array<int, array{amount: string, category: string|null, date: string, description: string}>
     */
    public function getDeductibleJournals(int $profileId, Carbon $start, Carbon $end): array;
}
