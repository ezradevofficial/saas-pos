<?php

namespace App\Core\Limits;

use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LIM-01 until billing binds its own PlanAllowances: the allowances of the
 * selection the tenant picked at onboarding or for its trial (ONB-03,
 * ONB-04; `onboarding_states.selection`, read under row-level security).
 * Without a selection (or before onboarding exists) everything is
 * unlimited.
 *
 * From the selection and the catalogue (ADR 013):
 * - outlets: the outlet quantity (1 when not given) when a module sold per
 *   outlet is selected or in the bundle; unlimited otherwise;
 * - devices: the device quantity, at least the devices the modules include
 *   (POS: 2 per outlet), when a module includes devices; unlimited otherwise;
 * - employees: the employee quantity (1 when not given) when a module sold
 *   per employee is selected (HR, Payroll); unlimited otherwise;
 * - users: unlimited (the catalogue sells no user unit).
 */
class SelectionAllowances implements PlanAllowances
{
    private const DEVICE_ENTRY = 'pos_device';

    private ?bool $hasTable = null;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Catalogue $catalogue,
    ) {}

    public function for(string $tenantId): Allowances
    {
        $selection = $this->tenants->run($tenantId, fn () => $this->selection());

        if (! is_array($selection) || ! is_string($selection['country'] ?? null)) {
            return Allowances::unlimited();
        }

        $country = $selection['country'];
        $modules = array_values(array_filter((array) ($selection['modules'] ?? []), 'is_string'));
        $quantities = array_map('intval', array_filter((array) ($selection['quantities'] ?? []), 'is_numeric'));
        $bundle = is_string($selection['bundle'] ?? null) ? $selection['bundle'] : null;

        try {
            $snapshot = $this->catalogue->snapshot($country);
        } catch (ApiException) {
            return Allowances::unlimited();
        }

        $taken = array_values(array_unique([...$modules, ...($bundle !== null ? ($snapshot->bundle($bundle)?->modules ?? []) : [])]));
        $quantity = fn (string $unit) => max(0, $quantities[$unit] ?? 1);
        $outletSold = false;
        $employeeSold = false;
        $includedDevices = null;

        foreach ($taken as $key) {
            $module = $snapshot->module($key);

            if ($module === null) {
                continue;
            }

            $outletSold = $outletSold || $module->unit === 'outlet';
            $employeeSold = $employeeSold || $module->unit === 'employee';

            if (isset($module->includes[self::DEVICE_ENTRY])) {
                $includedDevices = ($includedDevices ?? 0) + max(0, (int) $module->includes[self::DEVICE_ENTRY]) * max(1, $quantity($module->unit));
            }
        }

        return new Allowances(
            [
                Units::OUTLET => $outletSold ? $quantity('outlet') : null,
                Units::DEVICE => $includedDevices === null ? null : max($includedDevices, $quantities['device'] ?? 0),
                Units::USER => null,
                Units::EMPLOYEE => $employeeSold ? $quantity('employee') : null,
            ],
            new PlanSelection($country, $modules, $quantities, $bundle),
        );
    }

    /** The current tenant's onboarding selection, when onboarding is installed. */
    private function selection(): ?array
    {
        $this->hasTable ??= Schema::connection(TenantContext::CONNECTION)->hasTable('onboarding_states');

        if (! $this->hasTable) {
            return null;
        }

        $raw = DB::connection(TenantContext::CONNECTION)->table('onboarding_states')->value('selection');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : null;
    }
}
