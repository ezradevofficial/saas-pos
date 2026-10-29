<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * Fixed assets (user manual, batch B): three categories, five assets (four in use, one draft),
 * two depreciation runs, a transfer waiting for approval and one applied, a scrapped printer,
 * an impairment, and a service schedule with a work order.
 */
class FixedAssetsSection
{
    use WaitsForApproval;

    public function run(DemoData $demo): void
    {
        $api = $demo->api;

        if ($api->rows('fixed-assets/assets') !== []) {
            return;
        }

        $company = $demo->ids['company'];
        $currency = $demo->ids['currency'];
        $categories = [];

        foreach ([['FURN', 'Furniture and fittings', 'straight_line', 96, '5'], ['VEH', 'Motor vehicles', 'straight_line', 60, '10'], ['COMP', 'Computers and peripherals', 'straight_line', 36, '0'], ['EQUIP', 'Kitchen and shop equipment', 'straight_line', 84, '5']] as [$code, $name, $method, $months, $residual]) {
            $existing = collect($api->rows('fixed-assets/categories', ['company_id' => $company]))->firstWhere('code', $code);
            $categories[$code] = $existing['id'] ?? $api->post('fixed-assets/categories', ['company_id' => $company, 'code' => $code, 'name' => $name, 'method' => $method, 'useful_life_months' => $months, 'residual_percent' => $residual])['data']['id'];
        }

        $mainOutlet = $demo->ids['locations'][0];
        $westlands = $demo->ids['locations'][1] ?? $mainOutlet;
        $serviceFrom = now()->subMonths(3)->startOfMonth();
        $assets = [];

        foreach ([
            ['van', 'Delivery van, Isuzu NPR', 'VEH', '485000000', 'KDG 214X'],
            ['freezer', 'Chest freezer, 500 litres', 'EQUIP', '12800000', 'CF-5001-77'],
            ['laptop', 'Laptop for the back office', 'COMP', '9500000', 'LT-90412'],
            ['printer', 'Receipt and label printer', 'COMP', '1800000', 'PR-2231'],
        ] as [$key, $name, $category, $cost, $serial]) {
            $asset = $api->post('fixed-assets/assets', [
                'name' => $name, 'location_id' => $mainOutlet, 'category_id' => $categories[$category], 'serial_number' => $serial,
                'acquired_on' => $serviceFrom->toDateString(), 'in_service_on' => $serviceFrom->toDateString(), 'currency' => $currency, 'cost_minor' => (int) $cost,
            ])['data'];
            $api->post("fixed-assets/assets/{$asset['id']}/activate", ['in_service_on' => $serviceFrom->toDateString()]);
            $assets[$key] = $asset['id'];
        }

        // A draft, waiting to be put into service.
        $api->post('fixed-assets/assets', [
            'name' => 'Office desks and chairs (6 sets)', 'location_id' => $westlands, 'category_id' => $categories['FURN'],
            'acquired_on' => now()->toDateString(), 'currency' => $currency, 'cost_minor' => 4200000,
        ]);

        // Two months of depreciation, posted in order.
        foreach ([2, 1] as $back) {
            try {
                $api->post('fixed-assets/runs', ['company_id' => $company, 'period' => now()->subMonths($back)->format('Y-m')]);
            } catch (Throwable) {
                break;
            }
        }

        $this->movements($demo, $assets, $westlands, $currency);
        $this->maintenance($demo, $assets);
    }

    /** @param  array<string, string>  $assets */
    private function movements(DemoData $demo, array $assets, string $westlands, string $currency): void
    {
        $api = $demo->api;

        try {
            $applied = $api->post("fixed-assets/assets/{$assets['freezer']}/transfers", ['to_location_id' => $westlands, 'transfer_date' => now()->toDateString(), 'reason' => 'Westlands outlet needs cold storage']);
            $this->approveUntil($demo, 'branch_manager', fn () => $api->get("fixed-assets/transfers/{$applied['data']['id']}")['data']['status'] !== 'pending', 10);
        } catch (Throwable) {
            // Transfers are optional for the pictures.
        }

        try {
            $disposal = $api->post("fixed-assets/assets/{$assets['printer']}/disposals", ['kind' => 'scrap', 'disposal_date' => now()->toDateString(), 'reason' => 'Print head failed and is not worth repairing'])['data'];
            $this->approveUntil($demo, 'branch_manager', fn () => $api->get("fixed-assets/disposals/{$disposal['id']}")['data']['status'] === 'approved', 10);
            $api->post("fixed-assets/disposals/{$disposal['id']}/post");
        } catch (Throwable) {
        }

        try {
            $api->post("fixed-assets/assets/{$assets['van']}/revaluations", ['kind' => 'impairment', 'revalued_on' => now()->toDateString(), 'value_minor' => 40000000, 'reason' => 'Accident damage: repair cost above the recoverable amount']);
        } catch (Throwable) {
        }

        // The last request stays pending, so the transfers list and the approvals inbox show one waiting.
        try {
            $api->post("fixed-assets/assets/{$assets['laptop']}/transfers", ['to_location_id' => $westlands, 'transfer_date' => now()->addDays(2)->toDateString(), 'reason' => 'Moves with the accountant to the Westlands office']);
        } catch (Throwable) {
        }
    }

    /** @param  array<string, string>  $assets */
    private function maintenance(DemoData $demo, array $assets): void
    {
        $api = $demo->api;

        try {
            $schedule = $api->post("fixed-assets/assets/{$assets['van']}/schedules", ['name' => 'Service every 3 months', 'basis' => 'time', 'interval_days' => 90, 'warning_days' => 14, 'last_done_on' => now()->subDays(60)->toDateString()])['data'];
            $api->post("fixed-assets/assets/{$assets['van']}/work-orders", ['title' => 'Replace front brake pads', 'schedule_id' => $schedule['id'], 'due_on' => now()->addDays(10)->toDateString()]);
            $api->post("fixed-assets/assets/{$assets['freezer']}/work-orders", ['title' => 'Clean the condenser and check the seal', 'due_on' => now()->addDays(21)->toDateString()]);
        } catch (Throwable) {
        }
    }
}
