<?php

namespace Modules\Salon\Support;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;

/**
 * SAL-02, SAL-18: the checks that keep the service menu consistent. Every id a request names is read under the tenant
 * and must belong to the company: an item the company can sell (its own or shared), staff and resources of the company,
 * add-ons that are add-on services.
 */
class Catalogue
{
    /** An item of the company (or shared) of the type a service or package is sold as. */
    public function item(string $companyId, string $itemId, string $field = 'item_id', array $types = ['service', 'non_stock']): Item
    {
        $item = Item::query()->find($itemId);

        if ($item === null || $item->archived_at !== null || ($item->company_id !== null && $item->company_id !== $companyId)) {
            throw Errors::make(422, 'item_unknown', field: $field);
        }

        if (! in_array($item->type, $types, true)) {
            throw Errors::make(422, 'item_not_service', field: $field);
        }

        return $item;
    }

    /**
     * Normalises and checks the salon data of a service.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function service(string $companyId, array $data, ?string $ownId = null): array
    {
        $out = $data;

        foreach (['staff_ids' => [Staff::class, 'staff_unknown'], 'resource_ids' => [Resource::class, 'resource_unknown']] as $key => [$model, $code]) {
            if (array_key_exists($key, $data)) {
                $ids = array_values(array_unique((array) $data[$key]));

                if ($ids !== [] && $model::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($ids)->count() !== count($ids)) {
                    throw Errors::make(422, $code, field: $key);
                }

                $out[$key] = $ids;
            }
        }

        if (array_key_exists('addon_ids', $data)) {
            $ids = array_values(array_unique((array) $data['addon_ids']));

            if ($ids !== [] && (in_array($ownId, $ids, true) || Service::query()->where('company_id', $companyId)->where('kind', 'addon')->whereNull('archived_at')->whereKey($ids)->count() !== count($ids))) {
                throw Errors::make(422, 'addon_unknown', field: 'addon_ids');
            }

            $out['addon_ids'] = $ids;
        }

        if (array_key_exists('products', $data)) {
            $out['products'] = [];

            foreach ((array) $data['products'] as $index => $product) {
                $this->item($companyId, (string) $product['item_id'], "products.{$index}.item_id", ['stock']);
                $out['products'][] = ['item_id' => $product['item_id'], 'qty' => (string) $product['qty']];
            }
        }

        return $out;
    }

    /** SAL-18: the services a package covers belong to the company. @param list<string> $ids @return list<string> */
    public function coveredServices(string $companyId, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids !== [] && Service::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($ids)->count() !== count($ids)) {
            throw Errors::make(422, 'service_unknown', field: 'service_ids');
        }

        return $ids;
    }

    /** One item can be sold as one service or one package type at a time (the till rings an item, not a salon record). */
    public function assertItemFree(string $companyId, string $itemId, ?string $ownServiceId = null, ?string $ownTypeId = null): void
    {
        $taken = Service::query()->where('company_id', $companyId)->whereNull('archived_at')->where('item_id', $itemId)->when($ownServiceId !== null, fn ($q) => $q->whereKeyNot($ownServiceId))->exists()
            || PackageType::query()->where('company_id', $companyId)->whereNull('archived_at')->where('item_id', $itemId)->when($ownTypeId !== null, fn ($q) => $q->whereKeyNot($ownTypeId))->exists();

        if ($taken) {
            throw Errors::make(422, 'item_taken', field: 'item_id');
        }
    }

    /**
     * SAL-17: the company's `salon_credit` payment method, made once (the till offers it only while the module is on).
     * The name is the tenant's own text, in its default language.
     */
    public function creditMethod(string $companyId): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $companyId)->where('type', 'salon_credit')->whereNull('archived_at')->first()
            ?? PaymentMethod::query()->create([
                'company_id' => $companyId, 'type' => 'salon_credit', 'name' => __('salon.credit_method', [], Tenant::query()->find(app(TenantContext::class)->require())?->default_locale ?: 'en'),
                'currency' => null, 'active' => true, 'position' => 90,
            ]);
    }
}
