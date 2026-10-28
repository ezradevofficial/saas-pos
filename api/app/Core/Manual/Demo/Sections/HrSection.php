<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Carbon\Carbon;

/** Positions, six employees with contracts, KRA PINs and M-Pesa pay accounts, the statutory leave types (country pack), and leave requests. */
class HrSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];

        if ($api->rows('hr/employees') !== []) {
            return;
        }

        $positions = [];

        foreach ([['CASH', 'Cashier'], ['SUP', 'Shop supervisor'], ['STK', 'Storekeeper']] as [$code, $title]) {
            $positions[$code] = $api->post('hr/positions', ['company_id' => $company, 'code' => $code, 'title' => $title])['data']['id'];
        }

        $branches = array_values($demo->ids['branches']);
        $people = [
            ['Wanjiru', 'Kamau', 'F', 'SUP', '85000', 'A012345678B', '23456789', '0722 411 001'],
            ['Otieno', 'Odhiambo', 'M', 'CASH', '38000', 'A023456789C', '24567890', '0733 411 002'],
            ['Achieng', 'Atieno', 'F', 'CASH', '36000', 'A034567890D', '25678901', '0711 411 003'],
            ['Mohamed', 'Hassan', 'M', 'STK', '42000', 'A045678901E', '26789012', '0700 411 004'],
            ['Faith', 'Chebet', 'F', 'CASH', '35000', 'A056789012F', '27890123', '0722 411 005'],
            ['Brian', 'Mutiso', 'M', 'SUP', '72000', 'A067890123G', '28901234', '0733 411 006'],
        ];
        $employees = [];

        foreach ($people as $n => [$first, $last, $gender, $position, $pay, $pin, $nationalId, $phone]) {
            $employee = $api->post('hr/employees', [
                'company_id' => $company,
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $gender === 'F' ? 'female' : 'male',
                'hire_date' => now()->subYears(1 + ($n % 3))->subMonths($n)->format('Y-m-d'),
                'branch_id' => $branches[$n % count($branches)],
                'position_id' => $positions[$position],
                'work_phone' => $phone,
                'work_email' => strtolower("{$first}.{$last}").'@demotrading.example.com',
            ])['data'];
            $api->put("hr/employees/{$employee['id']}/identifiers", ['identifiers' => [['type' => 'kra_pin', 'value' => $pin], ['type' => 'national_id', 'value' => $nationalId]]]);
            $api->post("hr/employees/{$employee['id']}/contracts", [
                'type' => 'permanent', 'start_date' => $employee['hire_date'], 'position_id' => $positions[$position],
                'pay_basis' => 'monthly', 'pay_amount' => $pay.'.00', 'currency' => 'KES',
            ]);
            $api->post("hr/employees/{$employee['id']}/pay-accounts", [
                'kind' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => "{$first} {$last}",
                'account_number' => preg_replace('/\D/', '', $phone), 'currency' => 'KES', 'is_primary' => true,
            ]);
            $employees[] = $employee['id'];
        }

        $demo->ids['employees'] = $employees;

        $api->post('hr/leave-types/apply-pack', ['company_id' => $company]);
        $types = collect($api->rows('hr/leave-types', ['company_id' => $company]));
        $annual = $types->first(fn ($t) => stripos($t['name'], 'annual') !== false) ?? $types->first();
        $sick = $types->first(fn ($t) => stripos($t['name'], 'sick') !== false) ?? $annual;

        // Opening balances: the statutory entitlement carried in as of the start of the year (HR-13).
        foreach ($employees as $employee) {
            foreach ([[$annual, '21'], [$sick, '14']] as [$type, $days]) {
                $api->post('hr/leave-adjustments', ['employee_id' => $employee, 'leave_type_id' => $type['id'], 'days' => $days, 'reason' => 'Opening balance for the year']);
            }
        }

        foreach ([[1, $annual, 12, 5, 'Family visit upcountry'], [2, $sick, 3, 2, 'Malaria, doctor note attached'], [4, $annual, 25, 5, 'School holidays'], [3, $annual, 40, 3, 'Wedding in Kisumu']] as [$who, $type, $inDays, $length, $reason]) {
            $start = now()->addDays($inDays)->next(Carbon::MONDAY);
            $api->post('hr/leave-requests', [
                'employee_id' => $employees[$who],
                'leave_type_id' => $type['id'],
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $start->copy()->addDays($length - 1)->format('Y-m-d'),
                'reason' => $reason,
            ]);
        }
    }
}
