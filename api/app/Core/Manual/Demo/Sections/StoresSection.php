<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/**
 * Stores (user manual, batch B): a kitchen department and cost centre, a requisition
 * approved and fully issued (with a part returned), and a second one waiting for approval.
 */
class StoresSection
{
    use WaitsForApproval;

    public function run(DemoData $demo): void
    {
        $api = $demo->api;

        if ($api->rows('stores/requisitions') !== []) {
            return;
        }

        $company = $demo->ids['company'];
        $department = collect($api->rows("companies/{$company}/departments"))->first()
            ?? $api->post("companies/{$company}/departments", ['code' => 'KIT', 'name' => 'Kitchen'])['data'];
        $costCentre = collect($api->rows("companies/{$company}/cost-centres"))->first()
            ?? $api->post("companies/{$company}/cost-centres", ['code' => 'CC-KIT', 'name' => 'Kitchen and catering'])['data'];
        $store = $this->store($demo);

        $first = $api->post('stores/requisitions', [
            'location_id' => $store, 'department_id' => $department['id'], 'cost_centre_id' => $costCentre['id'],
            'needed_by' => now()->addDays(3)->toDateString(), 'note' => 'Weekly kitchen supplies',
            'lines' => [
                ['item_id' => $this->sample($demo, '08'), 'qty' => '12'],
                ['item_id' => $this->sample($demo, '06'), 'qty' => '20'],
                ['item_id' => $this->sample($demo, '09'), 'qty' => '10', 'note' => 'Hand soap for the staff washroom'],
            ],
        ])['data'];
        $this->approveUntil($demo, 'branch_manager', fn () => $api->get("stores/requisitions/{$first['id']}")['data']['status'] === 'approved');

        $requisition = $api->get("stores/requisitions/{$first['id']}")['data'];

        if ($requisition['status'] === 'approved') {
            $issue = $api->post("stores/requisitions/{$requisition['id']}/issues", [
                'lines' => collect($requisition['lines'])->map(fn ($line) => ['requisition_line_id' => $line['id'], 'qty' => (string) (int) $line['qty']])->all(),
            ])['data'];
            $api->as('branch_manager')->post("stores/issues/{$issue['id']}/acknowledge");
            $issued = $api->get("stores/issues/{$issue['id']}")['data'];
            $api->post("stores/issues/{$issue['id']}/returns", [
                'reason' => 'excess',
                'lines' => [['issue_line_id' => $issued['lines'][0]['id'], 'qty' => '2']],
            ]);
        }

        // A second request still waits for the department head.
        $second = $api->post('stores/requisitions', [
            'location_id' => $store, 'department_id' => $department['id'], 'needed_by' => now()->addDays(6)->toDateString(),
            'lines' => [['item_id' => $this->sample($demo, '10'), 'qty' => '24'], ['item_id' => $this->sample($demo, '11'), 'qty' => '40']],
        ])['data'];
        unset($second);
    }

    /** The main store of the first branch, with opening stock (the other locations are outlets and a van). */
    private function store(DemoData $demo): string
    {
        $api = $demo->api;
        $branch = array_values($demo->ids['branches'])[0];
        $store = collect($api->rows('locations'))->firstWhere('name', 'Main store')
            ?? $api->post("branches/{$branch}/locations", ['name' => 'Main store', 'type' => 'store'])['data'];

        if ($api->rows('inventory/stock', ['location_id' => $store['id']]) === []) {
            foreach (['05', '06', '07', '08', '09', '10', '11'] as $n => $number) {
                $api->post('inventory/opening-stock', ['location_id' => $store['id'], 'item_id' => $this->sample($demo, $number), 'qty' => (string) (150 + $n * 10), 'unit_cost' => '90.00']);
            }
        }

        return $store['id'];
    }
}
