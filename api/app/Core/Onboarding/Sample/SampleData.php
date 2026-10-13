<?php

namespace App\Core\Onboarding\Sample;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceWriter;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ONB-06: sample data to explore with, loaded and removed in one click.
 *
 * Loading creates one batch: item categories, items priced in the first
 * company's default price list (a sample list when it has none) and
 * customers tagged `sample`, named once in the tenant's language. The
 * batch's ids are kept on the onboarding record. Removing archives the
 * whole batch (TEN-06), or nothing when a real record now depends on a
 * sample one (SampleDependents: core's references and each module's).
 * Each record's create and archive is audited, and the batch as a whole
 * (`core.onboarding.sample_load`, `core.onboarding.sample_remove`).
 *
 * No sample sales: a sale is uploaded by a paired till and queued for the
 * tax authority (KRA eTIMS, DRC DGI), and a sample must never reach it.
 */
class SampleData
{
    /** category key => item keys */
    private const CATALOGUE = [
        'drinks' => ['water', 'soda', 'juice', 'tea'],
        'groceries' => ['rice', 'sugar', 'flour', 'oil'],
        'household' => ['soap', 'detergent', 'tissue', 'matches'],
    ];

    /** Unit prices in major units per currency (whole numbers so CDF works too). */
    private const PRICES = [
        'KES' => [60, 80, 120, 150, 220, 180, 160, 350, 90, 250, 70, 20],
        'USD' => [1, 1, 2, 2, 3, 2, 2, 4, 1, 3, 1, 1],
        'CDF' => [1500, 2000, 3000, 3500, 5500, 4500, 4000, 9000, 2500, 6500, 2000, 500],
    ];

    private const CUSTOMERS = ['customer_1', 'customer_2', 'customer_3'];

    public const TAG = 'sample';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly MasterDataSharing $sharing,
        private readonly PriceWriter $prices,
        private readonly SampleDependents $dependents,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{batch_id: string, loaded_at: string, counts: array<string, int>}|null */
    public function current(): ?array
    {
        $sample = OnboardingState::query()->first()?->sample;

        if ($sample === null || ($sample['removed_at'] ?? null) !== null) {
            return null;
        }

        return [
            'batch_id' => $sample['batch_id'],
            'loaded_at' => $sample['loaded_at'],
            'counts' => array_map('count', $sample['records']),
        ];
    }

    public function load(User $actor): OnboardingState
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor) {
            $state = OnboardingState::locked();

            if ($state->sample !== null && ($state->sample['removed_at'] ?? null) === null) {
                throw new ApiException(409, 'sample_loaded', __('onboarding.errors.sample_loaded'));
            }

            $locale = Tenant::findOrFail($this->tenants->require())->default_locale;
            $company = Company::query()->whereNull('archived_at')->orderBy('created_at')->orderBy('id')->firstOrFail();
            $itemCompany = $this->sharing->isShared(ItemSharing::DATA_TYPE) ? null : $company->id;
            $partyCompany = $this->sharing->isShared('customers') ? null : $company->id;
            $uom = Uom::query()->whereRaw('upper(code) = ?', ['EA'])->whereNull('archived_at')->firstOrFail();
            $suffix = Str::upper(Str::random(4));
            $records = ['item_category' => [], 'item' => [], 'item_price' => [], 'party' => [], 'price_list' => []];

            $priceList = PriceList::query()->where('company_id', $company->id)->where('is_default', true)->whereNull('archived_at')->first();
            if ($priceList === null) {
                $priceList = PriceList::create([
                    'company_id' => $company->id,
                    'name' => __('onboarding.sample.price_list', [], $locale),
                    'currency' => $company->base_currency,
                    'tax_inclusive' => true,
                    'is_default' => true,
                ]);
                $records['price_list'][] = $priceList->id;
            }

            $amounts = self::PRICES[$priceList->currency] ?? self::PRICES['USD'];
            $decimals = $priceList->currency === 'CDF' ? 0 : 2;
            $rows = [];
            $n = 0;

            foreach (self::CATALOGUE as $categoryKey => $itemKeys) {
                $category = ItemCategory::create([
                    'company_id' => $itemCompany,
                    'name' => __("onboarding.sample.categories.{$categoryKey}", [], $locale),
                ]);
                $records['item_category'][] = $category->id;

                foreach ($itemKeys as $itemKey) {
                    $item = Item::create([
                        'company_id' => $itemCompany,
                        'code' => sprintf('SAMPLE-%s-%02d', $suffix, $n + 1),
                        'name' => __("onboarding.sample.items.{$itemKey}", [], $locale),
                        'category_id' => $category->id,
                        'type' => 'stock',
                        'base_uom_id' => $uom->id,
                    ]);
                    $records['item'][] = $item->id;
                    $rows[] = [
                        'item_id' => $item->id,
                        'uom_id' => $uom->id,
                        'amount_minor' => (string) ($amounts[$n] * 10 ** $decimals),
                        'currency' => $priceList->currency,
                    ];
                    $n++;
                }
            }

            foreach ($this->prices->write($priceList, $rows) as ['price' => $price]) {
                $records['item_price'][] = $price->id;
            }

            foreach (self::CUSTOMERS as $customerKey) {
                $party = Party::create([
                    'company_id' => $partyCompany,
                    'kind' => 'person',
                    'name' => __("onboarding.sample.customers.{$customerKey}", [], $locale),
                    'roles' => ['customer'],
                    'tags' => [self::TAG],
                ]);
                $records['party'][] = $party->id;
            }

            $batch = (string) Str::uuid7();
            $state->sample = [
                'batch_id' => $batch,
                'loaded_at' => now()->toIso8601String(),
                'loaded_by' => $actor->id,
                'records' => $records,
                'removed_at' => null,
            ];
            $state->save();

            $this->auditor->record('core.onboarding.sample_load', $state, null, ['batch_id' => $batch, 'counts' => array_map('count', $records)]);

            return $state;
        });
    }

    public function remove(User $actor): OnboardingState
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor) {
            $state = OnboardingState::locked();
            $sample = $state->sample;

            if ($sample === null || ($sample['removed_at'] ?? null) !== null) {
                throw new ApiException(404, 'sample_not_loaded', __('onboarding.errors.sample_not_loaded'));
            }

            $records = $sample['records'];
            $blocking = $this->dependents->find($records);

            if ($blocking !== []) {
                throw new ApiException(422, 'sample_in_use', __('onboarding.errors.sample_in_use', ['count' => count($blocking)]), [], ['blocking' => $blocking]);
            }

            foreach (ItemPrice::query()->whereIn('id', $records['item_price'])->whereNull('archived_at')->get() as $price) {
                $this->prices->archive($price);
            }

            foreach ([Item::class => 'item', ItemCategory::class => 'item_category', Party::class => 'party', PriceList::class => 'price_list'] as $model => $type) {
                $model::query()->whereIn('id', $records[$type] ?? [])->whereNull('archived_at')->get()->each->archive();
            }

            $state->sample = [...$sample, 'removed_at' => now()->toIso8601String(), 'removed_by' => $actor->id];
            $state->save();

            $this->auditor->record('core.onboarding.sample_remove', $state, null, ['batch_id' => $sample['batch_id'], 'counts' => array_map('count', $records)]);

            return $state;
        });
    }

    /** Ids of the live sample batch of $type (items, parties, ...), for checks that skip sample records. */
    public static function ids(string $type): array
    {
        $sample = OnboardingState::query()->first()?->sample;

        return $sample === null || ($sample['removed_at'] ?? null) !== null ? [] : ($sample['records'][$type] ?? []);
    }
}
