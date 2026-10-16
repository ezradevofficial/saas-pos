<?php

namespace Modules\Accounting\Integrations;

use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Payroll\Events\PayrollPosted;
use Ramsey\Uuid\Uuid;

/**
 * PAY-22 (Payroll → Accounting): a posted payroll run as posting sources
 * (`payroll.run`), one per department and pay currency of the run
 * (payroll.md decision 8), each idempotent by an id derived from the run
 * and the group, so a replay posts nothing twice. Built from the event
 * alone, never from Payroll's tables.
 *
 * Fields: `gross` (pay expense), `employer.{code}` (employer contributions:
 * the default rule debits the expense and credits the liability),
 * `statutory.{code}` (employee statutory deductions, income tax
 * included), `deduction.{code}` (other deductions), `loans` (staff loans
 * repaid) and `net_pay` (net pay payable). gross = Σ statutory + Σ
 * deduction + loans + net_pay, so each journal balances; a correction's
 * amounts are signed differences (a negative amount posts on the other
 * side). The department is a dimension; the pay currency's rate to the
 * base is the run's (CUR-04). Each line names its component (`labels`:
 * "Gross pay", "PAYE", "NSSF employer share", "Net pay payable"; the
 * component names come with the event, accounting.md decision 33).
 */
class PayrollSources
{
    /** @return list<SourceDocument> */
    public function posted(PayrollPosted $event): array
    {
        $sources = [];

        foreach ($event->groups as $group) {
            $amounts = array_filter($group['amounts'], fn (string $minor) => $minor !== '0');

            if ($amounts === []) {
                continue;
            }

            $sources[] = new SourceDocument(
                companyId: $event->companyId,
                sourceType: 'payroll.run',
                sourceId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'payroll-run:'.$event->runId.':'.$group['key'])->toString(),
                date: CarbonImmutable::parse($event->payDate.' 12:00:00', 'UTC'),
                currency: $group['currency'],
                amounts: $amounts,
                fx: isset($group['fx']) ? Integrations::fx($group['fx']) : null,
                dimensions: array_filter(['department_id' => $group['department_id'] ?? null, 'branch_id' => $group['branch_id'] ?? null]),
                reference: mb_substr($event->number, 0, 80),
                memo: Integrations::memo('payroll.run', $event->number),
                link: ['kind' => 'payroll.run', 'id' => $event->runId],
                labels: $this->labels(array_keys($amounts), (array) ($group['labels'] ?? [])),
            );
        }

        return $sources;
    }

    /**
     * ACC-33: a description for each field's lines.
     *
     * @param  list<string>  $fields
     * @param  array<string, string>  $names  component code => name
     * @return array<string, string>
     */
    private function labels(array $fields, array $names): array
    {
        $labels = [];

        foreach ($fields as $field) {
            [$prefix, $code] = str_contains($field, '.') ? explode('.', $field, 2) : [$field, null];
            $name = $code === null ? null : ($names[$code] ?? strtoupper($code));
            $labels[$field] = match ($prefix) {
                'gross', 'net_pay', 'loans' => __('accounting.integrations.payroll.'.$prefix),
                'employer' => __('accounting.integrations.payroll.employer', ['name' => $name]),
                default => (string) $name,
            };
        }

        return $labels;
    }
}
