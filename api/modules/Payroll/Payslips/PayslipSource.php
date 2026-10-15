<?php

namespace Modules\Payroll\Payslips;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\SampleSource;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigInteger;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\PayrollServiceProvider;

/**
 * TPL-01, PAY-19: the live data source of `payroll.payslip`. Lines are
 * the earnings (positive), then the statutory deductions, other
 * deductions and loans (negative); totals are gross (subtotal), the
 * employee's statutory deductions (tax) and net pay (total); document
 * fields carry the employee, the period, the pay date, the employer's
 * contributions and the rates used (CUR-04). Only Payroll's own
 * controllers load it, after checking who may see the payslip (the
 * employee or `payroll.payslip.view`).
 */
final class PayslipSource implements RecordSource
{
    public const TYPE = 'payroll.payslip';

    public const FIELDS = [
        'document.employee' => 'text',
        'document.employee_number' => 'text',
        'document.period' => 'text',
        'document.pay_date' => 'date',
        'document.run' => 'text',
        'document.gross' => 'money',
        'document.net' => 'money',
        'document.employer_contributions' => 'money',
        'document.taxable_pay' => 'money',
        'document.rates' => 'text',
    ];

    public function type(): string
    {
        return self::TYPE;
    }

    public function documentFields(): array
    {
        return [...SampleSource::CATALOGUE[self::TYPE][0], ...self::FIELDS];
    }

    public function lineColumns(): array
    {
        return SampleSource::CATALOGUE[self::TYPE][1];
    }

    public function sample(?string $country): array
    {
        return (new SampleSource(self::TYPE))->sample($country);
    }

    public function live(): bool
    {
        return app(ModuleRegistry::class)->isActive(PayrollServiceProvider::MODULE);
    }

    public function load(string $recordId): ?DocumentData
    {
        if (! $this->live()) {
            return null;
        }

        $payslip = Payslip::query()->find($recordId);

        if ($payslip === null) {
            return null;
        }

        $run = Run::query()->findOrFail($payslip->run_id);
        $line = RunLine::query()->findOrFail($payslip->run_line_id);
        $company = Company::query()->findOrFail($payslip->company_id);
        $branch = $line->branch_id === null ? null : Branch::query()->find($line->branch_id);
        $currency = $line->pay_currency;
        $decimals = app(CurrencyDecimals::class);
        $money = fn (int|string $minor, ?string $in = null) => ['minor' => (string) $minor, 'currency' => $in ?? $currency];
        $negated = fn (int|string $minor) => (string) BigInteger::of((string) $minor)->negated();
        $lines = [];

        foreach ($line->earnings as $earning) {
            $lines[] = ['description' => $earning['name'], 'amount' => $money($earning['amount_minor'])];
        }

        foreach ($line->statutory as $component) {
            if ($component['kind'] !== 'allowance' && $component['employee_minor'] !== '0') {
                $lines[] = ['description' => $component['name'], 'amount' => $money($negated($component['employee_minor']))];
            }
        }

        foreach ($line->deductions as $deduction) {
            $lines[] = ['description' => $deduction['name'], 'amount' => $money($negated($deduction['amount_minor']))];
        }

        foreach ($line->loans as $loan) {
            $lines[] = ['description' => trim(__('payroll.loans.kinds.'.$loan['kind']).' '.($loan['reference'] ?? '')), 'amount' => $money($negated($loan['amount_minor']))];
        }

        $rates = [];

        foreach ($line->fx ?? [] as $fx) {
            $rates[] = "1 {$fx['base']} = {$fx['rate']} {$fx['quote']}";
        }

        $statutoryCurrency = $line->statutory_currency ?? $currency;
        $data = [
            'currencies' => [$currency => $decimals->for($currency), $statutoryCurrency => $decimals->for($statutoryCurrency)],
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => ['name' => $branch?->name, 'code' => $branch?->code, 'address' => null],
            'location' => ['name' => null, 'code' => null],
            'document' => [
                'number' => $payslip->number,
                'date' => $run->pay_date->toDateString(),
                'currency' => $currency,
                'reference' => $run->number,
                'employee' => $line->name,
                'employee_number' => $line->number,
                'period' => $run->period_start->toDateString().' – '.$run->period_end->toDateString(),
                'pay_date' => $run->pay_date->toDateString(),
                'run' => $run->number.($run->kind === Run::CORRECTION ? ' ('.__('payroll.kinds.correction').')' : ''),
                'gross' => $money($line->gross_minor),
                'net' => $money($line->net_minor),
                'employer_contributions' => $money($line->employer_minor),
                'taxable_pay' => $money($line->taxable_statutory_minor, $statutoryCurrency),
                'rates' => implode('; ', $rates),
            ],
            'customer' => null,
            'lines' => $lines,
            'totals' => [
                'subtotal' => $money($line->gross_minor),
                'discount' => $money('0'),
                'tax' => $money($line->statutory_minor),
                'total' => $money($line->net_minor),
                'dual' => null,
                'tax_lines' => [],
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData(self::TYPE, $payslip->id, $data, $company->id, $line->branch_id, $company->country);
    }
}
