<?php

namespace Modules\Ecommerce\Catalogue;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-01: a store's settings, checked against its company (under RLS:
 * another tenant's ids do not exist): its branch and locations are the
 * company's; its price list is the company's in the store currency; each
 * payment kind names an active payment method of the company of the type
 * it needs (MD-04) and, when the method has a currency, the store's; the
 * delivery fee's tax code is the company's. A store goes live only with a
 * way to pay and a way to receive the goods. Audited (the model).
 */
final class Stores
{
    /** @param array<string, mixed> $data validated */
    public function create(array $data): Store
    {
        return Tx::run(function () use ($data) {
            $store = new Store(['company_id' => $data['company_id'], 'status' => Store::DRAFT]);
            $this->fill($store, $data);
            $store->save();

            return $store;
        });
    }

    /** @param array<string, mixed> $data validated */
    public function update(Store $store, array $data): Store
    {
        return Tx::run(function () use ($store, $data) {
            $store = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->fill($store, $data);
            $store->save();

            return $store;
        });
    }

    /** ECOM-24: a new webhook token (shown once; the store keeps its sha256). */
    public function rotateWhatsApp(Store $store): string
    {
        $token = Str::random(40);
        $store->forceFill(['whatsapp_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /** @param array<string, mixed> $data */
    private function fill(Store $store, array $data): void
    {
        $store->fill(array_intersect_key($data, array_flip([
            'branch_id', 'location_id', 'handle', 'name', 'status', 'currency', 'price_list_id', 'delivery_enabled', 'pickup_enabled', 'pickup_location_ids',
            'min_order_minor', 'reservation_minutes', 'safety_buffer', 'contact_phone', 'contact_email', 'whatsapp_number', 'delivery_tax_code_id', 'payment_methods',
        ])));

        $branch = Branch::query()->find($store->branch_id);

        if ($branch === null || $branch->company_id !== $store->company_id) {
            throw EcomError::make('branch_not_of_company', field: 'branch_id');
        }

        $location = Location::query()->find($store->location_id);

        if ($location === null || $location->branch_id !== $branch->id) {
            throw EcomError::make('location_not_of_branch', field: 'location_id');
        }

        $pickups = array_values(array_unique($store->pickup_location_ids ?? []));
        $branches = Branch::query()->where('company_id', $store->company_id)->pluck('id');

        if ($pickups !== [] && Location::query()->whereKey($pickups)->whereIn('branch_id', $branches)->count() !== count($pickups)) {
            throw EcomError::make('location_not_of_company', field: 'pickup_location_ids');
        }

        $store->pickup_location_ids = $pickups;

        if (Store::query()->where('handle', $store->handle)->whereKeyNot($store->id ?? '00000000-0000-0000-0000-000000000000')->exists()) {
            throw EcomError::make('handle_taken', field: 'handle');
        }

        $list = PriceList::query()->find($store->price_list_id);

        if ($list === null || $list->company_id !== $store->company_id || $list->currency !== $store->currency) {
            throw EcomError::make('price_list_invalid', field: 'price_list_id');
        }

        if ($store->delivery_tax_code_id !== null && TaxCode::query()->whereKey($store->delivery_tax_code_id)->where('company_id', $store->company_id)->doesntExist()) {
            throw EcomError::make('tax_code_not_of_company', field: 'delivery_tax_code_id');
        }

        $methods = [];

        foreach ($store->payment_methods ?? [] as $index => $entry) {
            $method = PaymentMethod::query()->find($entry['payment_method_id'] ?? null);
            $type = Store::PAYMENT_KINDS[$entry['kind'] ?? ''] ?? null;

            if ($method === null || $method->company_id !== $store->company_id || $method->type !== $type || ($method->currency !== null && $method->currency !== $store->currency)) {
                throw EcomError::make('payment_method_invalid', ['kind' => __('ecommerce.payment_kinds.'.($entry['kind'] ?? 'cod'))], "payment_methods.{$index}.payment_method_id");
            }

            $methods[] = ['kind' => $entry['kind'], 'payment_method_id' => $method->id];
        }

        $store->payment_methods = $methods;

        if ($store->status === Store::LIVE && ($methods === [] || (! $store->delivery_enabled && ! $store->pickup_enabled) || ($store->pickup_enabled && $pickups === []))) {
            throw EcomError::make('store_not_ready', field: 'status');
        }
    }
}
