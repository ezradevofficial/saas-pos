<?php

namespace App\Core\MasterData\Sharing;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TEN-08: whether each master data type (items, customers, suppliers,
 * employees) is shared across the group's companies or kept per company.
 *
 * - shared: records have no company and every holder of the permission in
 *   the tenant sees them.
 * - per_company: records have a company and are seen by users whose scope
 *   touches it (the company, or one of its branches or locations).
 *
 * Writers of a type take a shared advisory lock on it (lockForWrite) and a
 * switch takes it exclusively, so no record is created under the old mode
 * while the mode changes. Switching shared to per_company assigns every
 * record with no company to one company (`assign_to_company_id`), or is
 * refused with `records_need_company`; per_company to shared needs
 * `confirm` and clears the companies. Registered guards may refuse a switch
 * (codes that would collide). One transaction, audited.
 */
class MasterDataSharing
{
    public const SHARED = 'shared';

    public const PER_COMPANY = 'per_company';

    public const MODES = [self::SHARED, self::PER_COMPANY];

    public const DATA_TYPES = ['items', 'customers', 'suppliers', 'employees'];

    /** @var array<string, list<SharedRecords>> */
    private array $records = [];

    /** @var list<SharingSwitchGuard> */
    private array $guards = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function records(string $dataType, SharedRecords $records): void
    {
        $this->assertType($dataType);
        $this->records[$dataType][] = $records;
    }

    public function guard(SharingSwitchGuard $guard): void
    {
        $this->guards[] = $guard;
    }

    public function mode(string $dataType): string
    {
        $this->assertType($dataType);

        return MasterDataSetting::query()->where('data_type', $dataType)->value('mode') ?? self::SHARED;
    }

    public function isShared(string $dataType): bool
    {
        return $this->mode($dataType) === self::SHARED;
    }

    /** @return array<string, array{data_type: string, mode: string, changed_at: ?string}> */
    public function all(): array
    {
        $rows = MasterDataSetting::query()->get()->keyBy('data_type');

        return collect(self::DATA_TYPES)->mapWithKeys(fn (string $type) => [$type => [
            'data_type' => $type,
            'mode' => $rows->get($type)?->mode ?? self::SHARED,
            'changed_at' => $rows->get($type)?->changed_at?->toIso8601String(),
        ]])->all();
    }

    /**
     * Hold the types' modes steady until the current transaction ends
     * (shared lock; a switch waits for writers and writers for a switch).
     *
     * @param  list<string>  $dataTypes
     */
    public function lockForWrite(array $dataTypes): void
    {
        $this->lock($dataTypes, exclusive: false);
    }

    /**
     * @return array{assigned: int, released: int}
     *
     * @throws ApiException records_need_company | confirmation_required
     */
    public function switch(string $dataType, string $mode, ?string $assignToCompanyId = null, bool $confirm = false): array
    {
        $this->assertType($dataType);

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown sharing mode [{$mode}].");
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($dataType, $mode, $assignToCompanyId, $confirm) {
            $this->lock([$dataType], exclusive: true);
            $from = $this->mode($dataType);
            $result = ['assigned' => 0, 'released' => 0];

            if ($from === $mode) {
                return $result;
            }

            if ($mode === self::PER_COMPANY) {
                $count = array_sum(array_map(fn (SharedRecords $r) => $r->unassignedCount(), $this->records[$dataType] ?? []));

                if ($count > 0 && $assignToCompanyId === null) {
                    throw new ApiException(422, 'records_need_company', trans_choice('core.master_data.records_need_company', $count, ['count' => $count]), extra: ['count' => $count]);
                }
            } elseif (! $confirm) {
                throw new ApiException(422, 'confirmation_required', __('core.master_data.confirm_shared'));
            }

            foreach ($this->guards as $guard) {
                $guard->check($dataType, $mode);
            }

            $setting = MasterDataSetting::query()->firstOrNew(['data_type' => $dataType]);
            $setting->fill(['mode' => $mode, 'changed_at' => now()])->save();

            foreach ($this->records[$dataType] ?? [] as $records) {
                if ($mode === self::PER_COMPANY) {
                    $result['assigned'] += $assignToCompanyId === null ? 0 : $records->assignTo($assignToCompanyId);
                } else {
                    $result['released'] += $records->release();
                }
            }

            $this->auditor->record('core.master_data_settings.update', $setting, ['data_type' => $dataType, 'mode' => $from], [
                'data_type' => $dataType,
                'mode' => $mode,
                'assign_to_company_id' => $mode === self::PER_COMPANY ? $assignToCompanyId : null,
                ...$result,
            ]);

            return $result;
        });
    }

    /** @param list<string> $dataTypes */
    private function lock(array $dataTypes, bool $exclusive): void
    {
        $tenantId = $this->tenants->require();
        $db = DB::connection(TenantContext::CONNECTION);
        $types = array_values(array_unique($dataTypes));
        sort($types);

        foreach ($types as $type) {
            $this->assertType($type);
            $db->select(
                $exclusive ? 'select pg_advisory_xact_lock(hashtext(?))' : 'select pg_advisory_xact_lock_shared(hashtext(?))',
                ["master_data_sharing:{$tenantId}:{$type}"],
            );
        }
    }

    private function assertType(string $dataType): void
    {
        if (! in_array($dataType, self::DATA_TYPES, true)) {
            throw new InvalidArgumentException("Unknown master data type [{$dataType}].");
        }
    }
}
