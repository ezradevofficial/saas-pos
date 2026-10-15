<?php

namespace Modules\Payroll\Payments;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigInteger;
use Modules\HR\Contracts\PayAccounts;
use Modules\Payroll\Events\PaymentFileGenerated;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Support\Errors;

/**
 * PAY-20: payment files of a posted run, built on demand and never stored
 * (decision 7). Each employee with a positive net pay is paid by their
 * payment method (bank or mobile money; cash is left out) into their
 * primary active account of that kind (HR's `PayAccounts`, read for the
 * user: `hr.bank.view` at the employee's place, audited by HR), in the
 * pay currency when they have one there. Employees without a method or a
 * fitting account are listed, not guessed. Each build is audited and
 * raises PaymentFileGenerated.
 */
class PaymentFiles
{
    public function __construct(
        private readonly PaymentFormats $formats,
        private readonly PayAccounts $accounts,
        private readonly CurrencyDecimals $decimals,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @return array{rows: list<array<string, ?string>>, missing: list<array{number: string, name: string, reason: string}>, cash: int, totals: array<string, string>}
     */
    public function rows(Run $run, PaymentFormat $format, User $user): array
    {
        if (! $run->isPosted()) {
            throw Errors::make(422, 'run_not_posted');
        }

        $lines = RunLine::query()->where('run_id', $run->id)->orderBy('number')->get();
        $employees = PayrollEmployee::query()->whereIn('id', $lines->pluck('payroll_employee_id'))->get()->keyBy('id');
        $rows = [];
        $missing = [];
        $cash = 0;
        $totals = [];

        foreach ($lines as $line) {
            if ($line->net_minor <= 0) {
                continue;
            }

            $method = $employees[$line->payroll_employee_id]?->payment_method;

            if ($method === 'cash') {
                $cash++;

                continue;
            }

            if ($method === null) {
                $missing[] = ['number' => $line->number, 'name' => $line->name, 'reason' => 'no_method'];

                continue;
            }

            if (! in_array($method, $format->methods(), true)) {
                continue;
            }

            $accounts = array_values(array_filter($this->accounts->forUser($line->employee_id, $user), fn (array $a) => $a['kind'] === $method));
            usort($accounts, fn (array $a, array $b) => [($b['currency'] === $line->pay_currency), $b['is_primary']] <=> [($a['currency'] === $line->pay_currency), $a['is_primary']]);
            $account = $accounts[0] ?? null;

            if ($account === null || $account['currency'] !== $line->pay_currency) {
                $missing[] = ['number' => $line->number, 'name' => $line->name, 'reason' => $account === null ? 'no_account' : 'account_currency'];

                continue;
            }

            $rows[] = [
                'employee_number' => $line->number,
                'name' => $line->name,
                'method' => $method,
                'provider' => $account['provider'],
                'branch_code' => $account['branch_code'],
                'account_name' => $account['account_name'],
                'account_number' => $account['account_number'],
                'currency' => $line->pay_currency,
                'amount' => Money::ofMinor($line->net_minor, $line->pay_currency)->toDecimalString($this->decimals),
                'amount_minor' => (string) $line->net_minor,
                'reference' => mb_substr($run->number.' '.$line->number, 0, 80),
            ];
            $totals[$line->pay_currency] = (string) BigInteger::of($totals[$line->pay_currency] ?? '0')->plus($line->net_minor);
        }

        return ['rows' => $rows, 'missing' => $missing, 'cash' => $cash, 'totals' => $totals];
    }

    /** The summary of a file (counts, totals, who is left out), without account numbers. */
    public function summary(Run $run, string $formatKey, User $user): array
    {
        $format = $this->format($formatKey);
        $built = $this->rows($run, $format, $user);

        return ['format' => $format->key(), 'employees' => count($built['rows']), 'totals' => $built['totals'], 'missing' => $built['missing'], 'cash' => $built['cash']];
    }

    /** @return array{content: string, file_name: string, content_type: string, needs_confirmation: bool} */
    public function build(Run $run, string $formatKey, User $user): array
    {
        $format = $this->format($formatKey);
        $built = $this->rows($run, $format, $user);
        $country = Company::query()->whereKey($run->company_id)->value('country');
        $file = $format->build($built['rows'], $country);

        if ($file['content'] === null) {
            throw Errors::make(422, (string) $file['error'], ['format' => __($format->label())]);
        }

        $this->auditor->record('payroll.payment_file.generate', $run, null, [
            'format' => $format->key(), 'employees' => count($built['rows']), 'left_out' => count($built['missing']), 'needs_confirmation' => $file['needs_confirmation'],
        ]);
        PaymentFileGenerated::dispatch($run->tenant_id, $run->id, $format->key(), count($built['rows']), $built['totals'], $user->id);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', ($file['needs_confirmation'] ? 'draft-' : '').$format->key().'-'.$run->number).'.'.$format->extension();

        return ['content' => $file['content'], 'file_name' => $name, 'content_type' => $format->contentType(), 'needs_confirmation' => $file['needs_confirmation']];
    }

    private function format(string $key): PaymentFormat
    {
        return $this->formats->find($key) ?? throw Errors::make(422, 'format_unknown', field: 'format');
    }
}
