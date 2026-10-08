<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\PackTaxCode;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CP-02, CP-03 (ADR 007): a published pack version reaches existing
 * tenants. For every company of the pack's country, each tax code copied
 * from the pack (`pack_code`) takes the pack's rate periods, but only when
 * all of that code's rate rows came from the pack (`source = pack`). A code
 * with any rate the tenant entered is skipped entirely: the tenant owns it.
 *
 * Periods are matched by start date: a pack period updates the row with
 * the same start, or is added. A pack-sourced row whose start date is no
 * longer in the pack is never deleted; the code is left alone and reported
 * as a conflict for staff to resolve.
 *
 * The owner connection only lists tenant ids. Each tenant is entered on
 * the runtime connection, under row-level security, in its own
 * transaction. Changes are audited as the system (no user):
 * `core.tax.pack_update` per code, besides the rate rows' own entries.
 * Idempotent: the same pack again changes nothing.
 */
class PropagateCountryPack
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditContext $audit,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @return array{tenants: int, updated: int, skipped: int, conflicts: list<string>}
     */
    public function run(CountryPack $pack): array
    {
        // The system acts: no user, device or request is recorded (AUD-02).
        $this->audit->reset();

        $ids = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');

        // Seeding runs with the owner as the default connection; tenant rows
        // must be read and written by the runtime role, under row-level security.
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(TenantContext::CONNECTION);

        $totals = ['tenants' => $ids->count(), 'updated' => 0, 'skipped' => 0, 'conflicts' => []];

        try {
            $periods = PackTaxCode::query()->where('country_pack_id', $pack->id)->get()
                ->reject(fn (PackTaxCode $row) => $row->kind === 'exempt')
                ->groupBy('code');

            if ($periods->isEmpty()) {
                return $totals;
            }

            foreach ($ids as $tenantId) {
                $result = $this->tenants->run($tenantId, fn () => DB::transaction(fn () => $this->tenant($pack, $periods)));

                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];

                foreach ($result['conflicts'] as $codeId) {
                    $totals['conflicts'][] = "tenant {$tenantId}, tax code {$codeId}";
                }
            }
        } finally {
            DB::setDefaultConnection($previous);
        }

        return $totals;
    }

    /**
     * @param  Collection<string, Collection<int, PackTaxCode>>  $periods  by pack code
     * @return array{updated: int, skipped: int, conflicts: list<string>}
     */
    private function tenant(CountryPack $pack, Collection $periods): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'conflicts' => []];

        $codes = TaxCode::query()
            ->whereIn('pack_code', $periods->keys()->all())
            ->whereHas('company', fn ($query) => $query->where('country', $pack->code))
            ->orderBy('id')
            // Serialised with TaxRates::add, which locks the code row too.
            ->lockForUpdate()
            ->get();

        foreach ($codes as $code) {
            $rows = TaxRate::query()->where('tax_code_id', $code->id)->orderBy('effective_from')->get();

            if ($rows->contains(fn (TaxRate $row) => $row->source !== TaxRate::SOURCE_PACK)) {
                $result['skipped']++;

                continue;
            }

            $wanted = $periods[$code->pack_code]->keyBy(fn (PackTaxCode $row) => $row->effective_from->toDateString());
            $existing = $rows->keyBy(fn (TaxRate $row) => $row->effective_from->toDateString());

            if ($existing->keys()->diff($wanted->keys())->isNotEmpty()) {
                $result['conflicts'][] = $code->id;

                continue;
            }

            $before = $this->summary($rows);
            $changed = false;

            // Existing periods first (closing a period never overlaps the next one).
            foreach ($wanted->sortKeys() as $from => $period) {
                $values = [
                    'rate' => $period->rate,
                    'effective_to' => $period->effective_to?->toDateString(),
                    'needs_confirmation' => $period->rate === null || $period->needs_confirmation,
                ];
                $row = $existing->get($from);

                if ($row === null) {
                    continue;
                }

                if ($row->rate !== $values['rate'] || $row->effective_to?->toDateString() !== $values['effective_to'] || $row->needs_confirmation !== $values['needs_confirmation']) {
                    $row->fill($values)->save();
                    $changed = true;
                }
            }

            foreach ($wanted->sortKeys() as $from => $period) {
                if ($existing->has($from)) {
                    continue;
                }

                TaxRate::create([
                    'tax_code_id' => $code->id,
                    'rate' => $period->rate,
                    'effective_from' => $from,
                    'effective_to' => $period->effective_to?->toDateString(),
                    'needs_confirmation' => $period->rate === null || $period->needs_confirmation,
                    'source' => TaxRate::SOURCE_PACK,
                ]);
                $changed = true;
            }

            if ($changed) {
                $after = $this->summary(TaxRate::query()->where('tax_code_id', $code->id)->orderBy('effective_from')->get());

                $this->auditor->record('core.tax.pack_update', $code, ['rates' => $before], [
                    'pack' => $pack->code,
                    'version' => $pack->version,
                    'rates' => $after,
                ], ['user_id' => null]);

                $result['updated']++;
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, TaxRate>  $rows
     * @return list<array{rate: ?string, effective_from: string, effective_to: ?string, needs_confirmation: bool}>
     */
    private function summary(Collection $rows): array
    {
        return $rows->map(fn (TaxRate $row) => [
            'rate' => $row->rate,
            'effective_from' => $row->effective_from->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'needs_confirmation' => $row->needs_confirmation,
        ])->values()->all();
    }
}
