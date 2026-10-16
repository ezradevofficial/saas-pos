<?php

namespace Modules\FixedAssets\Postings;

use Carbon\CarbonImmutable;
use Closure;
use Modules\FixedAssets\Events\AssetCapitalised;
use Modules\FixedAssets\Events\AssetDisposed;
use Modules\FixedAssets\Events\AssetImpaired;
use Modules\FixedAssets\Events\AssetRevalued;
use Modules\FixedAssets\Events\DepreciationPosted;
use Modules\FixedAssets\Events\DepreciationReversed;
use Modules\FixedAssets\Events\FixedAssetPosting;
use Modules\FixedAssets\Models\Posting;

/**
 * FA-22: what Fixed assets raises to the ledger. Each source document is
 * kept (`fa_postings`, its id the source id) and raised as its event after
 * the transaction commits; Accounting posts it when active, once. A source
 * whose amounts are all zero is not raised. replay() raises them again,
 * in date order, for the Accounting backfill (ACC-29).
 */
class Postings
{
    public const CAPITALISATION = 'fixed_assets.capitalisation';

    public const DEPRECIATION = 'fixed_assets.depreciation';

    public const REVALUATION = 'fixed_assets.revaluation';

    public const IMPAIRMENT = 'fixed_assets.impairment';

    public const DISPOSAL = 'fixed_assets.disposal';

    public const SOURCE_TYPES = [self::CAPITALISATION, self::DEPRECIATION, self::REVALUATION, self::IMPAIRMENT, self::DISPOSAL];

    /**
     * @param  array<string, int|string>  $amounts  field => signed base minor units
     * @param  array{kind: string, id: string}|null  $link
     */
    public function raise(string $sourceType, string $documentType, string $documentId, string $companyId, string $branchId, ?string $costCentreId,
        ?string $partyId, CarbonImmutable|string $date, string $currency, array $amounts, ?string $reference, ?array $link,
        bool $reverse = false, ?string $reverses = null): ?Posting
    {
        $amounts = array_map(fn ($minor) => (string) (int) $minor, $amounts);

        if (array_filter($amounts, fn (string $minor) => $minor !== '0') === []) {
            return null;
        }

        $posting = Posting::query()->create([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'branch_id' => $branchId,
            'cost_centre_id' => $costCentreId,
            'party_id' => $partyId,
            'posted_on' => $date instanceof CarbonImmutable ? $date->toDateString() : $date,
            'currency' => $currency,
            'amounts' => $amounts,
            'reference' => $reference === null ? null : mb_substr($reference, 0, 120),
            'reverse' => $reverse,
            'reverses_id' => $reverses,
        ]);

        event(self::event($posting, $link));

        return $posting;
    }

    /** The event of a kept posting. */
    public static function event(Posting $posting, ?array $link = null): FixedAssetPosting
    {
        $class = match (true) {
            $posting->source_type === self::CAPITALISATION => AssetCapitalised::class,
            $posting->source_type === self::DEPRECIATION && $posting->reverse => DepreciationReversed::class,
            $posting->source_type === self::DEPRECIATION => DepreciationPosted::class,
            $posting->source_type === self::REVALUATION => AssetRevalued::class,
            $posting->source_type === self::IMPAIRMENT => AssetImpaired::class,
            default => AssetDisposed::class,
        };

        return new $class(
            tenantId: (string) $posting->tenant_id,
            postingId: (string) $posting->id,
            companyId: (string) $posting->company_id,
            sourceType: (string) $posting->source_type,
            date: $posting->posted_on->toDateString(),
            currency: (string) $posting->currency,
            amounts: (array) $posting->amounts,
            dimensions: ['branch_id' => $posting->branch_id, 'cost_centre_id' => $posting->cost_centre_id, 'party_id' => $posting->party_id],
            reference: $posting->reference,
            link: $link ?? self::link($posting),
            reverse: (bool) $posting->reverse,
        );
    }

    /** ACC-29: every posting dated in [$from, $to], in date order. @param Closure(object): void $emit */
    public function replay(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        Posting::query()->whereBetween('posted_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('posted_on')->orderBy('created_at')->orderBy('id')
            ->each(fn (Posting $posting) => $emit(self::event($posting)));
    }

    /** The journal's drill-down: the run of a run's posting, else the asset. */
    private static function link(Posting $posting): array
    {
        return $posting->document_type === 'run'
            ? ['kind' => 'fixed_assets.run', 'id' => (string) $posting->document_id]
            : ['kind' => 'fixed_assets.'.$posting->document_type, 'id' => (string) $posting->document_id];
    }
}
