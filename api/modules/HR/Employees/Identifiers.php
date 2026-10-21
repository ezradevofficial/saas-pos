<?php

namespace Modules\HR\Employees;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Support\Digest;
use Modules\HR\Support\Errors;
use Modules\HR\Support\HrPack;
use Modules\HR\Support\Sensitive;

/**
 * HR-03, HR-10, HR-11: an employee's identifiers, of the types the
 * company's country pack lists. Values are encrypted (the model's cast),
 * unique per company and type through a keyed digest, and audited by type
 * only.
 */
class Identifiers
{
    public function __construct(
        private readonly HrPack $pack,
        private readonly Auditor $auditor,
    ) {}

    /** @return array<string, array{key: string, required: bool, pattern: ?string}> the company's identifier types */
    public function typesFor(string $companyId): array
    {
        return $this->pack->identifierTypes(Company::query()->whereKey($companyId)->value('country'));
    }

    /**
     * Set the given types. A type left out keeps its value, and so does an
     * empty value: only `remove: true` deletes one (clients send only what
     * the person changed).
     *
     * @param  list<array{type: string, value?: ?string, remove?: bool}>  $identifiers
     * @return list<array{field: string, code: string, message: string}> warnings (a value outside a format that only warns, CP-01)
     */
    public function put(Employee $employee, array $identifiers): array
    {
        $warnings = [];

        $types = $this->typesFor($employee->company_id);

        foreach ($identifiers as $index => $identifier) {
            $type = $types[$identifier['type']] ?? throw Errors::make(422, 'identifier_type_unknown', ['type' => $identifier['type']], "identifiers.{$index}.type");
            $value = trim((string) ($identifier['value'] ?? ''));

            if (($identifier['remove'] ?? false) !== true && $value !== '' && $type['pattern'] !== null && preg_match('/'.$type['pattern'].'/u', Digest::normalise($value)) !== 1) {
                // CP-01: an unconfirmed format (KE national ID: older numbers, Maisha Namba) only warns.
                if (($type['enforce'] ?? 'reject') === 'warn') {
                    $warnings[] = ['field' => "identifiers.{$index}.value", 'code' => 'identifier_format_unusual', 'message' => __('hr.errors.identifier_format_unusual', ['type' => HrPack::label('identifiers', $type['key'])])];

                    continue;
                }

                throw Errors::make(422, 'identifier_format', ['type' => HrPack::label('identifiers', $type['key'])], "identifiers.{$index}.value");
            }
        }

        Errors::transaction(function () use ($employee, $identifiers) {
            $changed = [];

            foreach ($identifiers as $index => $identifier) {
                $value = trim((string) ($identifier['value'] ?? ''));
                $current = EmployeeIdentifier::query()->where('employee_id', $employee->id)->where('type', $identifier['type'])->lockForUpdate()->first();

                if (($identifier['remove'] ?? false) === true) {
                    if ($current !== null) {
                        // Personal data, not a business record: a cleared identifier is gone (NFR-08).
                        $current->delete();
                        $changed[] = $identifier['type'];
                    }

                    continue;
                }

                if ($value === '') {
                    // Nothing typed: the value is kept.
                    continue;
                }

                $digest = Digest::of($identifier['type'], $value);

                if ($current?->value_digest === $digest) {
                    continue;
                }

                $taken = EmployeeIdentifier::query()->where('company_id', $employee->company_id)->where('type', $identifier['type'])
                    ->where('value_digest', $digest)->where('employee_id', '<>', $employee->id)->exists();

                if ($taken) {
                    throw Errors::make(422, 'identifier_taken', ['type' => HrPack::label('identifiers', $identifier['type'])], "identifiers.{$index}.value");
                }

                try {
                    ($current ?? new EmployeeIdentifier(['employee_id' => $employee->id, 'company_id' => $employee->company_id, 'type' => $identifier['type']]))
                        ->fill(['value' => $value, 'value_hint' => Sensitive::hintOf($value), 'value_digest' => $digest])
                        ->save();
                } catch (UniqueConstraintViolationException) {
                    throw Errors::make(422, 'identifier_taken', ['type' => HrPack::label('identifiers', $identifier['type'])], "identifiers.{$index}.value");
                }

                $changed[] = $identifier['type'];
            }

            if ($changed !== []) {
                // HR-11: which identifiers changed, never their values.
                $this->auditor->record('hr.employee.sensitive_update', $employee, null, ['fields' => ['identifiers'], 'types' => array_values(array_unique($changed))]);
            }
        });

        return $warnings;
    }
}
