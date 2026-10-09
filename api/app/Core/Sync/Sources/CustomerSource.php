<?php

namespace App\Core\Sync\Sources;

use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MD-01, NFR-04: customers a till can pick: parties with the customer role,
 * shared across the group or the device's company's, active only. A party
 * that stops being a customer or moves company leaves a tombstone.
 *
 * Only what a sale needs is sent: name, phone numbers, tax ID, currency,
 * price list, payment terms and credit limit. Not sent: emails, addresses,
 * legal name, tags, other roles. A device has no user, so field rules
 * (RBAC-05) cannot be applied here: each staff row carries its field rules
 * for `party`, and the till hides those fields from that cashier.
 * `custom` holds the values of the custom fields shown on the POS (CF-03;
 * CustomFieldSource). Version 2 added `custom`.
 */
class CustomerSource implements IncrementalSource
{
    public function key(): string
    {
        return 'customers';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 2;
    }

    public function table(): string
    {
        return 'parties';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query
            ->whereRaw("parties.roles @> array['customer']::text[]")
            ->where(fn (Builder $q) => $q->whereNull('parties.company_id')->orWhere('parties.company_id', $scope->companyId()));
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        $customFields = CustomFieldSource::posFields(PartyEntity::KEY);

        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('parties'), $scope)
            ->whereIn('parties.id', $ids)
            ->whereNull('parties.archived_at')
            ->get(['id', 'company_id', 'kind', 'name', 'tax_id', 'phones', 'currency', 'payment_terms_days', 'credit_limit_minor', 'credit_limit_currency', 'price_list_id', 'custom', 'updated_at'])
            ->mapWithKeys(fn (object $p) => [$p->id => [
                'id' => $p->id,
                'kind' => $p->kind,
                'name' => $p->name,
                'tax_id' => $p->tax_id,
                'phones' => array_values(array_map(
                    fn (array $phone) => ['number' => $phone['number'] ?? null, 'label' => $phone['label'] ?? null],
                    json_decode((string) $p->phones, true) ?: [],
                )),
                'currency' => $p->currency,
                'payment_terms_days' => $p->payment_terms_days === null ? null : (int) $p->payment_terms_days,
                'credit_limit' => $p->credit_limit_minor === null ? null : ['amount_minor' => (string) $p->credit_limit_minor, 'currency' => $p->credit_limit_currency],
                'price_list_id' => $p->price_list_id,
                'shared' => $p->company_id === null,
                'custom' => (object) CustomFieldSource::values($customFields, $p->custom),
                'updated_at' => Iso::of($p->updated_at),
            ]])
            ->all();
    }
}
