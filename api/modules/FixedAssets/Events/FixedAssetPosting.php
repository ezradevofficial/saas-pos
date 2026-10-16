<?php

namespace Modules\FixedAssets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * FA-22: a source document for the ledger (the ACC-07 contract,
 * docs/modules/fixed-assets.md, Events): `postingId` (the source id, so it
 * posts once), the company, the source type, the date (Y-m-d), the base
 * currency, `amounts` as minor digit strings by field, `dimensions`
 * {branch_id, cost_centre_id, party_id}, a reference, a drill-down link
 * `{kind, id}` and whether it reverses the rule's sides. Each concrete
 * event is one kind of fixed-asset fact; all balance by construction.
 */
abstract class FixedAssetPosting implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, string>  $amounts
     * @param  array<string, ?string>  $dimensions
     * @param  array{kind: string, id: string}|null  $link
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $postingId,
        public readonly string $companyId,
        public readonly string $sourceType,
        public readonly string $date,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly array $dimensions,
        public readonly ?string $reference = null,
        public readonly ?array $link = null,
        public readonly bool $reverse = false,
    ) {}
}
