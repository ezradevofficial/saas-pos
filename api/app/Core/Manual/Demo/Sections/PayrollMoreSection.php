<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** A salary advance to one employee, deducted by the next payroll runs (PAY-13). */
class PayrollMoreSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api->as('payroll_officer');

        if ($api->rows('payroll/loans') !== []) {
            return;
        }

        $employee = $api->rows('payroll/employees')[1] ?? null;

        if ($employee === null) {
            return;
        }

        $api->post('payroll/loans', [
            'payroll_employee_id' => $employee['id'], 'kind' => 'advance', 'reference' => 'ADV-001', 'principal_minor' => '3000000',
            'instalment_minor' => '500000', 'currency' => $demo->ids['currency'], 'disbursed_on' => now()->startOfMonth()->format('Y-m-d'),
            'note' => 'Advance for school fees, repaid over six months',
        ]);
    }
}
