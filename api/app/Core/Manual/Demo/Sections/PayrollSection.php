<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** A monthly pay group, this month's payroll run calculated with the Kenyan country pack's statutory values (CP-01), approved and posted. */
class PayrollSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];

        if ($api->rows('payroll/runs') !== []) {
            return;
        }

        $api->post('payroll/employees/sync', ['company_id' => $company]);
        $group = $api->post('payroll/pay-groups', ['company_id' => $company, 'code' => 'MONTHLY', 'name' => 'Monthly staff', 'frequency' => 'monthly', 'pay_day' => 28])['data'];

        foreach ($api->rows('payroll/employees') as $n => $employee) {
            $api->patch("payroll/employees/{$employee['id']}", [
                'pay_group_id' => $group['id'], 'pay_currency' => 'KES', 'payment_method' => 'mobile_money', 'dependants' => $n % 3,
            ]);
        }

        // The payroll officer prepares and submits; the Owner approves (never one's own run, APR-04).
        $officer = $api->as('payroll_officer');
        $run = $officer->post('payroll/runs', ['pay_group_id' => $group['id']])['data'];
        $officer->post("payroll/runs/{$run['id']}/calculate");
        $officer->post("payroll/runs/{$run['id']}/submit");
        $demo->approveAll('owner');
        $officer->post("payroll/runs/{$run['id']}/post");
    }
}
