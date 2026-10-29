<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * Sales: a team, leads in every stage (one converted, one disqualified), opportunities in the default pipeline,
 * quotes (a draft, one submitted, one sent), planned activities and a monthly target (CRM-01 to CRM-26).
 */
class CrmSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];
        $branch = array_values($demo->ids['branches'])[0];

        if ($api->rows('crm/leads') !== []) {
            return;
        }

        $rep = $demo->userId('sales_rep');
        $manager = $demo->userId('branch_manager');
        $pipeline = collect($api->rows('crm/pipelines', ['company' => $company]))->firstWhere('is_default', true);
        $stages = array_values($pipeline['stages'] ?? []);
        $sources = collect($api->rows('crm/options', ['company' => $company, 'kind' => 'source']))->pluck('id')->all();

        $api->post('crm/teams', ['company_id' => $company, 'branch_id' => $branch, 'name' => 'Nairobi sales', 'leader_id' => $manager, 'members' => [['user_id' => $manager], ['user_id' => $rep]]]);

        $lead = fn (string $name, string $organisation, string $phone, string $value, int $source, ?string $status = null) => $api->post('crm/leads', array_filter([
            'company_id' => $company, 'branch_id' => $branch, 'channel' => 'manual', 'name' => $name, 'organisation' => $organisation, 'phone' => $phone,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.co.ke', 'estimated_value_minor' => $value, 'currency' => $demo->ids['currency'],
            'source_id' => $sources[$source % max(count($sources), 1)] ?? null, 'owner_id' => $rep, 'status' => $status,
        ]))['data'];

        $first = $lead('Grace Wanjiru', 'Wanjiru Hardware', '0722 410 001', '45000000', 0);
        $second = $lead('Peter Otieno', 'Otieno Agrovet', '0733 410 002', '120000000', 1);
        $third = $lead('Fatuma Abdi', 'Abdi Wholesalers', '0711 410 003', '85000000', 2);
        $fourth = $lead('Daniel Kiprop', 'Kiprop Stores', '0700 410 004', '30000000', 0);
        $lead('Esther Njoki', 'Njoki Supermarket', '0722 410 005', '62000000', 1);

        $api->patch("crm/leads/{$second['id']}", ['status' => 'contacted']);
        $api->patch("crm/leads/{$third['id']}", ['status' => 'qualified']);
        $this->attempt(fn () => $api->post("crm/leads/{$fourth['id']}/disqualify", ['reason' => 'Bought from another supplier']));

        $converted = $this->attempt(fn () => $api->post("crm/leads/{$first['id']}/convert", [
            'party' => ['kind' => 'organisation', 'name' => 'Wanjiru Hardware', 'phones' => [['number' => '0722 410 001']], 'emails' => [['address' => 'grace.wanjiru@example.co.ke']]],
            'opportunity' => ['name' => 'Shop fit-out supplies', 'pipeline_id' => $pipeline['id'], 'stage_id' => $stages[0]['id'], 'value_minor' => '45000000', 'currency' => $demo->ids['currency'], 'expected_close_on' => now()->addDays(20)->format('Y-m-d')],
        ]));

        $customers = [];

        foreach ([['Kamau Distributors', 'Thika'], ['Mombasa Road Traders', 'Nairobi']] as [$name, $town]) {
            $customers[] = $api->post('parties', ['kind' => 'organisation', 'name' => $name, 'roles' => ['customer'], 'addresses' => [['line1' => $town, 'city' => $town, 'country' => 'KE']]])['data']['id'];
        }

        $opportunity = fn (string $name, string $party, int $stage, string $value, int $days) => $api->post('crm/opportunities', [
            'company_id' => $company, 'branch_id' => $branch, 'party_id' => $party, 'name' => $name, 'pipeline_id' => $pipeline['id'], 'stage_id' => $stages[min($stage, count($stages) - 1)]['id'],
            'value_minor' => $value, 'currency' => $demo->ids['currency'], 'expected_close_on' => now()->addDays($days)->format('Y-m-d'), 'owner_id' => $rep,
        ])['data'];

        $one = $opportunity('Quarterly stock supply', $customers[0], 1, '180000000', 14);
        $two = $opportunity('Shelving and display units', $customers[1], 2, '95000000', 30);

        $items = array_slice($demo->ids['items'], 0, 2);
        $quote = fn (string $party, ?string $opportunityId) => $api->post('crm/quotes', array_filter([
            'company_id' => $company, 'branch_id' => $branch, 'party_id' => $party, 'opportunity_id' => $opportunityId, 'currency' => $demo->ids['currency'],
            'terms' => 'Payment within 14 days of invoice. Prices valid for the period shown.', 'owner_id' => $rep,
            'lines' => [['item_id' => $items[0], 'qty' => '20'], ['item_id' => $items[1], 'qty' => '10']],
        ]))['data'];

        $quote($customers[1], $two['id']);
        $submitted = $quote($customers[0], $one['id']);
        $this->attempt(fn () => $api->post("crm/quotes/{$submitted['id']}/submit"));
        $demo->approveAll('branch_manager');

        $tomorrow = now('Africa/Nairobi')->addDay()->setTime(10, 0);

        foreach ([
            ['call', 'Call Grace about delivery dates', $tomorrow->copy()->subDay()->setTime(15, 0), ['opportunity_id' => $converted['data']['opportunity']['id'] ?? $one['id']]],
            ['meeting', 'Site visit at Otieno Agrovet', $tomorrow, ['lead_id' => $second['id']]],
            ['task', 'Send the price list to Fatuma', $tomorrow->copy()->addDays(2)->setTime(9, 0), ['lead_id' => $third['id']]],
            ['meeting', 'Review the quote with Kamau Distributors', $tomorrow->copy()->addDays(4)->setTime(11, 30), ['opportunity_id' => $one['id']]],
        ] as [$kind, $subject, $at, $link]) {
            $this->attempt(fn () => $api->post('crm/activities', ['branch_id' => $branch, 'kind' => $kind, 'subject' => $subject, 'starts_at' => $at->toIso8601String(), 'owner_id' => $rep] + $link));
        }

        $this->attempt(fn () => $api->post('crm/targets', [
            'company_id' => $company, 'subject_type' => 'user', 'subject_id' => $rep, 'metric' => 'won_value',
            'period_start' => now()->startOfMonth()->format('Y-m-d'), 'period_end' => now()->endOfMonth()->format('Y-m-d'), 'amount_minor' => '50000000',
        ]));
    }

    /** A step the sample does not depend on: a refusal leaves the rest of the data in place. */
    private function attempt(callable $step): array
    {
        try {
            return $step();
        } catch (Throwable) {
            return [];
        }
    }
}
