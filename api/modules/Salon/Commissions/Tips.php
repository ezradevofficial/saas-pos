<?php

namespace Modules\Salon\Commissions;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\Tip;

/**
 * SAL-22: the tip line of a sale (the settings' tip item) is shared among the staff the till names, their total at most
 * the tip; what is not named goes to the appointment's first staff member, and with nobody to give it to it is kept as an
 * exception. Staff are read under the tenant and must be active staff of the company. Tips are not commissionable.
 */
class Tips
{
    /**
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     * @param  list<array{staff_id: string, amount_minor: int|string}>  $named
     */
    public function take(string $companyId, array $sale, int $tip, array $named, ?string $fallbackStaffId): void
    {
        if ($tip <= 0) {
            return;
        }

        $valid = Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey(array_column($named, 'staff_id'))->pluck('id')->all();
        $shares = [];
        $left = $tip;

        foreach ($named as $share) {
            $amount = (int) $share['amount_minor'];

            if (! in_array($share['staff_id'], $valid, true) || $amount <= 0 || $amount > $left) {
                continue;
            }

            $shares[$share['staff_id']] = ($shares[$share['staff_id']] ?? 0) + $amount;
            $left -= $amount;
        }

        if ($left > 0) {
            $fallback = $fallbackStaffId !== null && Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($fallbackStaffId)->exists() ? $fallbackStaffId : null;

            if ($fallback === null) {
                SalonException::query()->firstOrCreate(['source_key' => "tip-unassigned:{$sale['id']}"], [
                    'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null, 'kind' => 'tip_unassigned', 'sale_id' => $sale['id'], 'amount_minor' => $left, 'currency' => $sale['currency'],
                ]);
            } else {
                $shares[$fallback] = ($shares[$fallback] ?? 0) + $left;
            }
        }

        foreach ($shares as $staffId => $amount) {
            Tip::query()->insertOrIgnore([[
                'id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->require(), 'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null,
                'staff_id' => $staffId, 'sale_id' => $sale['id'], 'kind' => 'tip', 'currency' => $sale['currency'], 'amount_minor' => $amount,
                'source_key' => "tip:{$sale['id']}:{$staffId}", 'earned_at' => CarbonImmutable::parse($sale['sold_at'])->utc(), 'created_at' => now(), 'updated_at' => now(),
            ]]);
        }
    }
}
