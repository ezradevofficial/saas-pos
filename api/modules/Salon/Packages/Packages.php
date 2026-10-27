<?php

namespace Modules\Salon\Packages;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Redemption;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Support\Errors;

/**
 * SAL-18: packages and memberships, prepaid bundles of N sessions.
 *
 * - A package type is a core service item; selling that item through the till sells the package. On `SaleCompleted`
 *   (sell()) one package of N sessions is made for the customer of the sale per unit sold, once per sale line and unit.
 * - A package's value is what was paid for it (the sale line's total). A session is worth the remaining value over the
 *   remaining sessions, the last one taking the rest, so what is left is exact at every step.
 * - Redemption is in Credits, under the package's row lock; a void of the sale takes a package back (void()).
 */
class Packages
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Makes the packages a completed sale sold.
     *
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     */
    public function sell(string $companyId, array $sale): void
    {
        $types = PackageType::query()->where('company_id', $companyId)->whereNull('archived_at')->get()->keyBy('item_id');

        if ($types->isEmpty()) {
            return;
        }

        $company = Company::query()->findOrFail($companyId);
        $soldAt = CarbonImmutable::parse($sale['sold_at'])->utc();
        $day = $soldAt->setTimezone($company->timezone ?: 'UTC');

        foreach ($sale['lines'] ?? [] as $line) {
            $type = $types->get($line['item_id'] ?? '');

            if ($type === null) {
                continue;
            }

            $customer = $sale['customer_id'] ?? null;
            $units = max(1, (int) BigDecimal::of((string) $line['qty'])->toScale(0, RoundingMode::Down)->toInt());

            if ($customer === null) {
                $this->exception($companyId, $sale, 'package_no_customer', "package-nocustomer:{$sale['id']}:{$line['id']}", ['package' => $type->name]);

                continue;
            }

            // The line's total is shared over the units; the first units take the odd minor units.
            $total = (int) $line['total_minor'];
            $each = intdiv($total, $units);
            $extra = $total - $each * $units;

            for ($unit = 1; $unit <= $units; $unit++) {
                $price = $each + ($unit <= $extra ? 1 : 0);
                $id = (string) Str::uuid7();
                $made = Package::query()->insertOrIgnore([[
                    'id' => $id, 'tenant_id' => $sale['tenant_id'] ?? app(TenantContext::class)->require(), 'company_id' => $companyId, 'type_id' => $type->id,
                    'customer_id' => $customer, 'name' => $type->name, 'sale_id' => $sale['id'], 'sale_line_id' => $line['id'], 'unit_no' => $unit,
                    'sessions_total' => $type->sessions, 'sessions_used' => 0, 'price_minor' => $price, 'remaining_minor' => $price,
                    'currency' => $sale['currency'], 'service_ids' => json_encode($type->service_ids ?? []), 'status' => Package::ACTIVE,
                    'expires_on' => $type->validity_days === null ? null : $day->addDays($type->validity_days)->toDateString(),
                    'created_at' => now(), 'updated_at' => now(),
                ]]);

                if ($made > 0) {
                    $this->auditor->record('salon.package.sell', Package::query()->find($id), null, ['sale_id' => $sale['id'], 'sessions' => $type->sessions, 'type' => $type->name]);
                }
            }
        }
    }

    /** A voided sale takes back the packages it sold; one already used from stays and is flagged. */
    public function void(string $companyId, array $sale): void
    {
        foreach (Package::query()->where('sale_id', $sale['id'])->lockForUpdate()->get() as $package) {
            if ($package->status === Package::VOID) {
                continue;
            }

            if ($package->sessions_used > 0) {
                $this->exception($companyId, $sale, 'package_used_on_void', "package-void:{$package->id}", ['package' => $package->name, 'sessions_used' => $package->sessions_used], $package->id);

                continue;
            }

            $package->forceFill(['status' => Package::VOID, 'remaining_minor' => 0])->save();
            $this->auditor->record('salon.package.void', $package, null, ['sale_id' => $sale['id']]);
        }
    }

    /**
     * A refund of a package item line takes back as many units as were refunded: unused ones are voided, used ones stay and
     * are flagged for a person. Once per refund line (a retried event finds its units marked).
     *
     * @param  array<string, mixed>  $refund  SaleSnapshot::refund()
     */
    public function refund(string $companyId, array $refund): void
    {
        foreach ($refund['lines'] ?? [] as $line) {
            $units = (int) BigDecimal::of((string) ($line['qty'] ?? '0'))->toScale(0, RoundingMode::Down)->toInt();

            if ($units < 1 || empty($line['sale_line_id'])) {
                continue;
            }

            $packages = Package::query()->where('company_id', $companyId)->where('sale_line_id', $line['sale_line_id'])->orderByDesc('unit_no')->lockForUpdate()->get();

            if ($packages->isEmpty()) {
                continue;
            }

            $left = $units - $packages->where('refund_line_id', $line['id'])->count();
            $order = $packages->whereNull('refund_line_id')->where('status', '<>', Package::VOID)->sortBy(fn (Package $p) => [$p->sessions_used > 0 ? 1 : 0, -$p->unit_no])->values();

            foreach ($order as $package) {
                if ($left <= 0) {
                    break;
                }

                $left--;
                $package->refund_line_id = $line['id'];

                if ($package->sessions_used > 0) {
                    $package->save();
                    $this->exception($companyId, ['id' => $refund['sale_id'], 'currency' => $refund['currency'], 'branch_id' => $refund['branch_id'] ?? null], 'package_used_on_refund', "package-refund:{$line['id']}:{$package->id}",
                        ['package' => $package->name, 'sessions_used' => $package->sessions_used], $package->id);

                    continue;
                }

                $package->forceFill(['status' => Package::VOID, 'remaining_minor' => 0])->save();
                $this->auditor->record('salon.package.void', $package, null, ['sale_id' => $refund['sale_id'], 'refund_id' => $refund['id']]);
            }
        }
    }

    /**
     * A person's correction of the sessions used (a session given by hand, a mistake), with a reason; the remaining value
     * follows the sessions left.
     */
    public function adjust(Package $package, int $sessionsUsed, string $reason, User $by): Package
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($package, $sessionsUsed, $reason, $by) {
            $locked = Package::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Package::VOID || $sessionsUsed < 0 || $sessionsUsed > $locked->sessions_total) {
                throw Errors::make(422, 'package_adjust_invalid', field: 'sessions_used');
            }

            $left = $locked->sessions_total - $sessionsUsed;
            $remaining = (int) BigDecimal::of((string) $locked->price_minor)->multipliedBy((string) $left)->dividedBy((string) $locked->sessions_total, 0, RoundingMode::HalfUp)->toInt();
            $before = ['sessions_used' => $locked->sessions_used, 'remaining_minor' => $locked->remaining_minor];

            $locked->forceFill([
                'sessions_used' => $sessionsUsed, 'remaining_minor' => $remaining,
                'status' => $left === 0 ? Package::EXHAUSTED : ($this->expired($locked) ? Package::EXPIRED : Package::ACTIVE),
            ])->save();
            $this->auditor->record('salon.package.adjust', $locked, $before, ['sessions_used' => $sessionsUsed, 'remaining_minor' => $remaining, 'reason' => $reason, 'by' => $by->id]);

            return $locked->refresh();
        });
    }

    /** Whether the package's validity has ended (a package is usable through its last day, in the company's time zone). */
    public function expired(Package $package, ?CarbonImmutable $at = null): bool
    {
        if ($package->expires_on === null) {
            return false;
        }

        $zone = Company::query()->whereKey($package->company_id)->value('timezone') ?: 'UTC';

        return ($at ?? CarbonImmutable::now())->setTimezone($zone)->toDateString() > $package->expires_on->toDateString();
    }

    /** Marks packages past their last day (the scheduled job). */
    public function expireDue(): int
    {
        $count = 0;

        foreach (Package::query()->where('status', Package::ACTIVE)->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->addDay()->toDateString())->lockForUpdate()->get() as $package) {
            if ($this->expired($package)) {
                $package->forceFill(['status' => Package::EXPIRED])->save();
                $count++;
            }
        }

        return $count;
    }

    /** A thing for a person to look at; once per key. */
    public function exception(string $companyId, array $sale, string $kind, string $key, array $detail, ?string $packageId = null, ?int $amount = null): void
    {
        SalonException::query()->firstOrCreate(['source_key' => $key], [
            'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null, 'kind' => $kind, 'sale_id' => $sale['id'] ?? null,
            'package_id' => $packageId, 'amount_minor' => $amount, 'currency' => $sale['currency'] ?? null, 'detail' => $detail,
        ]);
    }
}
