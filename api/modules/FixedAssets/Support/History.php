<?php

namespace Modules\FixedAssets\Support;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\CompanyClock;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetEvent;

/** FA-26: writes a line of an asset's history. */
class History
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(Asset $asset, string $kind, CarbonImmutable|string|null $on, ?array $before = null, ?array $after = null,
        ?string $documentType = null, ?string $documentId = null, ?string $note = null, ?User $by = null): AssetEvent
    {
        return AssetEvent::query()->create([
            'asset_id' => $asset->id,
            'kind' => $kind,
            // Without a date, today in the asset's company's time zone (not UTC's day).
            'happened_on' => $on instanceof CarbonImmutable ? $on->toDateString() : ($on ?? CompanyClock::today($asset->company_id)),
            'before' => $before,
            'after' => $after,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'note' => $note,
            'user_id' => $by?->id,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /** The book figures of $asset as history keeps them (base minor digit strings). */
    public static function book(Asset $asset): array
    {
        return [
            'cost' => (string) (int) $asset->book_cost_minor,
            'accumulated' => (string) (int) $asset->accumulated_minor,
            'impairment' => (string) (int) $asset->impairment_minor,
            'surplus' => (string) (int) $asset->surplus_minor,
            'book_value' => (string) $asset->bookValue(),
        ];
    }

    /** The depreciation terms of $asset (FA-11). */
    public static function terms(Asset $asset): array
    {
        return [
            'method' => $asset->method,
            'useful_life_months' => $asset->useful_life_months,
            'residual_minor' => (string) (int) $asset->residual_minor,
            'declining_rate' => $asset->declining_rate === null ? null : (string) $asset->declining_rate,
            'total_units' => $asset->total_units === null ? null : (string) $asset->total_units,
        ];
    }
}
