<?php

namespace Modules\FixedAssets\Assets;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use Modules\FixedAssets\Support\FaError;
use Modules\HR\Contracts\EmployeeDirectory;

/**
 * FA-04: an asset's custodian is a user of the tenant, or an employee of
 * its company still at work (status active), through HR's read contract
 * while the HR module itself is active (review #15), never both.
 */
class Custodians
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function hrActive(): bool
    {
        return class_exists(EmployeeDirectory::class) && $this->modules->isActive('hr');
    }

    /**
     * The custodian columns for $data (`custodian_user_id`,
     * `custodian_employee_id`), checked: 422 `custodian_both`,
     * `custodian_unknown`.
     *
     * @param  array<string, mixed>  $data
     * @return array{custodian_user_id: ?string, custodian_employee_id: ?string}
     */
    public function resolve(array $data, string $companyId, string $prefix = 'custodian'): array
    {
        $user = $data["{$prefix}_user_id"] ?? null;
        $employee = $data["{$prefix}_employee_id"] ?? null;

        if ($user !== null && $employee !== null) {
            throw FaError::make('custodian_both', field: "{$prefix}_employee_id");
        }

        if ($user !== null && User::query()->whereKey($user)->doesntExist()) {
            throw FaError::make('custodian_unknown', field: "{$prefix}_user_id");
        }

        if ($employee !== null) {
            $found = $this->hrActive() ? app(EmployeeDirectory::class)->find((string) $employee) : null;

            if ($found === null || $found['company_id'] !== $companyId || $found['status'] !== 'active') {
                throw FaError::make('custodian_unknown', field: "{$prefix}_employee_id");
            }
        }

        return ['custodian_user_id' => $user, 'custodian_employee_id' => $employee];
    }

    /** @param list<?string> $ids @return array<string, string> employee names by id (none while HR is off) */
    public function employeeNames(array $ids): array
    {
        return $this->hrActive() ? app(EmployeeDirectory::class)->names($ids) : [];
    }

    /** @return list<array{id: string, number: string, name: string}> */
    public function searchEmployees(string $companyId, string $search): array
    {
        if (! $this->hrActive()) {
            return [];
        }

        return array_map(fn (array $row) => ['id' => $row['id'], 'number' => $row['number'], 'name' => $row['name']],
            app(EmployeeDirectory::class)->search($companyId, $search));
    }
}
