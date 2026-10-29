<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * Manufacturing (user manual, batch B): the company's labour and overhead rates, a production
 * BOM for a packed maize meal mix (published), a recipe for the ugali dish, and a production
 * order released, issued and partly reported.
 */
class ManufacturingSection
{
    use WaitsForApproval;

    public function run(DemoData $demo): void
    {
        $api = $demo->api;

        $boms = $api->rows('manufacturing/boms');

        if ($boms !== []) {
            $production = collect($boms)->firstWhere('kind', 'production');

            if ($production !== null && $api->rows('manufacturing/orders') === []) {
                $this->order($demo, $production['item']['id']);
            }

            return;
        }

        $company = $demo->ids['company'];
        $flour = $this->sample($demo, '07');
        $sugar = $this->sample($demo, '06');
        $oil = $this->sample($demo, '08');
        $product = collect($api->rows('items', ['search' => 'MIX-MANDAZI']))->firstWhere('code', 'MIX-MANDAZI')
            ?? $demo->item('MIX-MANDAZI', 'Mandazi mix 1 kg', 'stock', 25000);

        $api->put("manufacturing/companies/{$company}/settings", ['labour_rate_minor' => '30000', 'overhead_rate_minor' => '15000', 'release_approval' => false]);

        $bom = $api->post('manufacturing/boms', [
            'company_id' => $company, 'item_id' => $product['id'], 'kind' => 'production', 'name' => 'Mandazi mix, 100 packs',
            'version' => [
                'output_qty' => '100', 'labour_minutes' => 90, 'note' => 'Mixed in the back kitchen, packed in 1 kg bags',
                'lines' => [
                    ['kind' => 'component', 'item_id' => $flour, 'qty' => '40', 'wastage_percent' => '2'],
                    ['kind' => 'component', 'item_id' => $sugar, 'qty' => '12'],
                    ['kind' => 'component', 'item_id' => $oil, 'qty' => '6', 'note' => 'Measured by weight'],
                ],
            ],
        ])['data'];
        $api->post("manufacturing/bom-versions/{$bom['draft_id']}/publish");

        // A recipe sold at the till: ugali and sukuma wiki.
        $dish = collect($api->rows('items', ['search' => 'UGALI']))->firstWhere('code', 'UGALI');

        if ($dish !== null) {
            try {
                $recipe = $api->post('manufacturing/boms', [
                    'company_id' => $company, 'item_id' => $dish['id'], 'kind' => 'recipe', 'name' => 'Ugali and sukuma wiki',
                    'version' => ['output_qty' => '1', 'labour_minutes' => 6, 'lines' => [
                        ['kind' => 'component', 'item_id' => $flour, 'qty' => '0.15'],
                        ['kind' => 'component', 'item_id' => $oil, 'qty' => '0.02'],
                    ]],
                ])['data'];
                $api->post("manufacturing/bom-versions/{$recipe['draft_id']}/publish");
            } catch (Throwable) {
                // The recipe screens still show the production BOM.
            }
        }

        $this->order($demo, $product['id']);
    }

    private function order(DemoData $demo, string $product): void
    {
        $api = $demo->api;
        $order = $api->post('manufacturing/orders', [
            'location_id' => $demo->ids['locations'][0], 'item_id' => $product, 'qty' => '100',
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(1)->toDateString(), 'note' => 'Saturday market stock',
        ])['data'];

        // A second order stays planned.
        $api->post('manufacturing/orders', [
            'location_id' => $demo->ids['locations'][0], 'item_id' => $product, 'qty' => '250',
            'planned_start' => now()->addDays(5)->toDateString(), 'planned_end' => now()->addDays(6)->toDateString(),
        ]);

        $api->post("manufacturing/orders/{$order['id']}/release");
        $order = $api->get("manufacturing/orders/{$order['id']}")['data'];
        $api->post("manufacturing/orders/{$order['id']}/issues", [
            'note' => 'First pick from the main outlet store',
            'lines' => collect($order['materials'])->map(fn ($material) => ['material_id' => $material['id'], 'qty' => (string) (int) ceil((float) $material['qty_required'])])->all(),
        ]);
        $api->post("manufacturing/orders/{$order['id']}/reports", ['qty_good' => '60', 'qty_scrap' => '2', 'scrap_reason' => 'damaged', 'labour_minutes' => 55]);
    }
}
