<?php

namespace Modules\Payroll\Returns;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\HR\Contracts\Identifiers;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Models\StatutoryReturn;
use Modules\Payroll\Payments\Csv;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Support\Errors;

/**
 * PAY-21: statutory returns from the posted runs (corrections included)
 * paid in a month or a year, per return type of the company's pack:
 * per employee, the identifiers the type names (HR's `Identifiers`, read
 * for the user: `hr.identity.view`, audited by HR), gross and taxable pay
 * and each component's employee and employer amounts in the pack
 * currency. The layout is the pack's when confirmed; else a placeholder
 * (`needs_confirmation`, file name `draft-…`). Only the record is kept;
 * the file is rebuilt on download (decision 7).
 */
class Returns
{
    public function __construct(
        private readonly PayrollPack $packs,
        private readonly Identifiers $identifiers,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<array<string, mixed>> the return types of $company's pack */
    public function types(Company $company): array
    {
        return array_map(fn (array $t) => [
            'key' => $t['key'], 'name' => PayrollPack::label('returns', $t['key']), 'period' => $t['period'],
            'components' => $t['components'], 'needs_confirmation' => (bool) ($t['needs_confirmation'] ?? true) || ($t['layout'] ?? null) === null,
        ], $this->packs->returnTypes($company->country));
    }

    public function generate(Company $company, string $type, string $period, User $by): StatutoryReturn
    {
        $definition = $this->definition($company, $type);
        [$start, $end] = $this->period($definition['period'], $period);
        $runs = Run::query()->where('company_id', $company->id)->where('status', Run::POSTED)
            ->whereDate('pay_date', '>=', $start)->whereDate('pay_date', '<=', $end)->orderBy('pay_date')->pluck('id')->all();

        if ($runs === []) {
            throw Errors::make(422, 'return_no_runs', field: 'period');
        }

        $rows = $this->rows($runs, $definition['components']);
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['components'] as $code => $amounts) {
                $totals[$code]['employee_minor'] = (string) BigInteger::of($totals[$code]['employee_minor'] ?? '0')->plus($amounts['employee_minor']);
                $totals[$code]['employer_minor'] = (string) BigInteger::of($totals[$code]['employer_minor'] ?? '0')->plus($amounts['employer_minor']);
            }
        }

        return StatutoryReturn::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'run_ids' => $runs,
            'totals' => ['currency' => $this->packs->currency($company->country), 'components' => $totals],
            'employee_count' => count($rows),
            'needs_confirmation' => (bool) ($definition['needs_confirmation'] ?? true) || ($definition['layout'] ?? null) === null,
            'generated_by' => $by->id,
        ]);
    }

    /** @return array{content: string, file_name: string} */
    public function file(StatutoryReturn $return, User $user): array
    {
        $company = Company::query()->findOrFail($return->company_id);
        $definition = $this->definition($company, $return->type);
        $rows = $this->rows($return->run_ids, $definition['components']);
        $identifierTypes = $definition['identifiers'] ?? [];
        $header = ['employee_number', 'name', ...$identifierTypes, 'gross_minor', 'taxable_pay_minor'];

        foreach ($definition['components'] as $code) {
            $header[] = "{$code}_employee_minor";
            $header[] = "{$code}_employer_minor";
        }

        $layout = $definition['layout'] ?? null;
        $columns = is_array($layout) && $layout !== [] && array_diff($layout, $header) === [] ? $layout : $header;
        $body = [];

        foreach ($rows as $row) {
            $ids = $identifierTypes === [] ? [] : $this->identifiers->forUser($row['employee_id'], $user);
            $cells = ['employee_number' => $row['number'], 'name' => $row['name'], 'gross_minor' => $row['gross_minor'], 'taxable_pay_minor' => $row['taxable_minor']];

            foreach ($identifierTypes as $typeKey) {
                $cells[$typeKey] = $ids[$typeKey] ?? '';
            }

            foreach ($definition['components'] as $code) {
                $cells["{$code}_employee_minor"] = $row['components'][$code]['employee_minor'] ?? '0';
                $cells["{$code}_employer_minor"] = $row['components'][$code]['employer_minor'] ?? '0';
            }

            $body[] = array_map(fn (string $c) => $cells[$c] ?? '', $columns);
        }

        $this->auditor->record('payroll.return.download', $return, null, ['type' => $return->type, 'employees' => count($rows), 'needs_confirmation' => $return->needs_confirmation]);
        $name = ($return->needs_confirmation ? 'draft-' : '')."{$return->type}-{$return->period_start->format('Y-m')}".($definition['period'] === 'year' ? '-year' : '').'.csv';

        return ['content' => Csv::of($columns, $body), 'file_name' => $name];
    }

    /**
     * Per employee: number, name, gross and taxable pay (pack currency) and the components' sums.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $runIds, array $components): array
    {
        $rows = [];

        foreach (RunLine::query()->whereIn('run_id', $runIds)->orderBy('number')->get() as $line) {
            $row = &$rows[$line->employee_id];
            $row ??= ['employee_id' => $line->employee_id, 'number' => $line->number, 'name' => $line->name, 'gross_minor' => '0', 'taxable_minor' => '0', 'components' => []];
            $row['taxable_minor'] = (string) BigInteger::of($row['taxable_minor'])->plus($line->taxable_statutory_minor);
            $row['gross_minor'] = (string) BigInteger::of($row['gross_minor'])->plus($line->gross_statutory_minor);

            foreach ($line->statutory as $c) {
                if (! in_array($c['code'], $components, true)) {
                    continue;
                }

                $row['components'][$c['code']]['employee_minor'] = (string) BigInteger::of($row['components'][$c['code']]['employee_minor'] ?? '0')->plus($c['employee_statutory_minor']);
                $row['components'][$c['code']]['employer_minor'] = (string) BigInteger::of($row['components'][$c['code']]['employer_minor'] ?? '0')->plus($c['employer_statutory_minor']);
            }

            unset($row);
        }

        return array_values($rows);
    }

    private function definition(Company $company, string $type): array
    {
        foreach ($this->packs->returnTypes($company->country) as $definition) {
            if ($definition['key'] === $type) {
                return $definition;
            }
        }

        throw Errors::make(422, 'return_type_unknown', field: 'type');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} a month (`2026-10`) or a year (`2026`) */
    private function period(string $kind, string $period): array
    {
        if ($kind === 'year') {
            if (preg_match('/^\d{4}\z/', $period) !== 1) {
                throw Errors::make(422, 'return_period', field: 'period');
            }

            $start = CarbonImmutable::create((int) $period, 1, 1);

            return [$start, $start->endOfYear()->startOfDay()];
        }

        if (preg_match('/^(\d{4})-(\d{2})\z/', $period, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw Errors::make(422, 'return_period', field: 'period');
        }

        $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

        return [$start, $start->endOfMonth()->startOfDay()];
    }
}
