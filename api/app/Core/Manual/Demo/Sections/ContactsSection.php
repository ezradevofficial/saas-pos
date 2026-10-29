<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/** A customer with a credit limit and a request to raise it, waiting for approval (WF-01). */
class ContactsSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api->as('accountant');

        if ($api->rows('credit-limit-changes') !== []) {
            return;
        }

        $customers = collect($demo->api->rows('parties'))->filter(fn ($party) => in_array('customer', $party['roles'] ?? [], true));
        $customer = $customers->first(fn ($party) => ($party['credit_limit'] ?? null) !== null);

        if ($customer === null && $customers->isNotEmpty()) {
            // The owner may set a limit directly (WF-01); a raise then goes through a request.
            $first = $customers->first();
            $demo->api->patch("parties/{$first['id']}", ['credit_limit' => '100000', 'credit_limit_currency' => $demo->ids['currency']]);
            $customer = $demo->api->get("parties/{$first['id']}")['data'];
        }

        if ($customer === null) {
            return;
        }

        $api->post('credit-limit-changes', [
            'party_id' => $customer['id'],
            'requested_limit' => ['amount_minor' => '30000000', 'currency' => $customer['credit_limit']['currency']],
            'reason' => 'Orders have doubled and they pay within 30 days.',
        ]);
    }
}
