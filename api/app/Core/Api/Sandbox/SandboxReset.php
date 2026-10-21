<?php

namespace App\Core\Api\Sandbox;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceWriter;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * API-05: a sandbox back to a fresh sample. Every active item price, item,
 * item category, party and price list is archived (TEN-06: never deleted,
 * even in a sandbox), then a new sample batch is loaded (ONB-06). Documents
 * made on top (sales, orders) keep their history. Live tenants are refused
 * (404, so the route never confirms a live tenant's shape). Audited as
 * `core.sandbox.reset` with what was archived.
 */
class SandboxReset
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly PriceWriter $prices,
        private readonly SampleData $sample,
        private readonly Auditor $auditor,
        private readonly SandboxCaps $caps,
    ) {}

    /** @return array<string, int> archived records by type */
    public function reset(User $actor): array
    {
        if (! $this->sandbox->active()) {
            throw new ApiException(404, 'not_sandbox', __('api.errors.not_sandbox'));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor) {
            $state = OnboardingState::locked();
            $archived = ['item_price' => 0];

            foreach (ItemPrice::query()->whereNull('archived_at')->get() as $price) {
                $this->prices->archive($price);
                $archived['item_price']++;
            }

            foreach ([Item::class => 'item', ItemCategory::class => 'item_category', Party::class => 'party', PriceList::class => 'price_list'] as $model => $type) {
                $records = $model::query()->whereNull('archived_at')->get();
                $records->each->archive();
                $archived[$type] = $records->count();
            }

            if ($state->sample !== null && ($state->sample['removed_at'] ?? null) === null) {
                $state->sample = [...$state->sample, 'removed_at' => now()->toIso8601String(), 'removed_by' => $actor->id];
                $state->save();
            }

            $this->auditor->record('core.sandbox.reset', $state, null, ['archived' => $archived]);
            $this->sample->load($actor);
            // The records cap counts from the reset.
            $this->caps->restartRecordCount();

            return $archived;
        });
    }
}
