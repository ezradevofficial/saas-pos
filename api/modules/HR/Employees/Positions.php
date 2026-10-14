<?php

namespace Modules\HR\Employees;

use App\Core\MasterData\Dimensions\Department;
use Modules\HR\Models\Position;
use Modules\HR\Support\Errors;

/** HR-08: positions of a company (code unique among its active ones). */
class Positions
{
    /** @param array<string, mixed> $data validated */
    public function create(array $data): Position
    {
        $this->check($data['company_id'], $data, null);

        return Errors::transaction(fn () => Position::create(array_intersect_key($data, array_flip(['company_id', 'code', 'title', 'department_id', 'grade']))));
    }

    /** @param array<string, mixed> $data validated */
    public function update(Position $position, array $data): Position
    {
        $this->check($position->company_id, $data, $position);
        $position->fill(array_intersect_key($data, array_flip(['code', 'title', 'department_id', 'grade'])))->save();

        return $position->refresh();
    }

    public function restore(Position $position): Position
    {
        $this->check($position->company_id, ['code' => $position->code], $position);
        $position->restore();

        return $position->refresh();
    }

    /** @param array<string, mixed> $data */
    private function check(string $companyId, array $data, ?Position $current): void
    {
        if (($data['department_id'] ?? null) !== null && ! Department::query()->whereKey($data['department_id'])->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'department_other_company', field: 'department_id');
        }

        if (isset($data['code'])) {
            $taken = Position::query()->where('company_id', $companyId)->whereNull('archived_at')->whereRaw('lower(code) = lower(?)', [$data['code']])
                ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists();

            if ($taken) {
                throw Errors::make(422, 'code_taken', field: 'code');
            }
        }
    }
}
