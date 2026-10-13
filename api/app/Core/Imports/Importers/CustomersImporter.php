<?php

namespace App\Core\Imports\Importers;

use App\Core\MasterData\Parties\PartyRoles;

/** IMP-01: customers (parties with the customer role, MD-01). */
class CustomersImporter extends PartiesImporter
{
    public function key(): string
    {
        return 'customers';
    }

    protected function role(): string
    {
        return PartyRoles::CUSTOMER;
    }

    public function example(): array
    {
        return [
            'name' => 'Duka Moja Ltd', 'company' => 'Main company', 'kind' => 'organisation', 'legal_name' => 'Duka Moja Limited',
            'tax_id' => 'P051234567A', 'phones' => '+254712345678', 'emails' => 'orders@example.com', 'address_line1' => 'Moi Avenue 12',
            'city' => 'Nairobi', 'country' => 'KE', 'currency' => 'KES', 'payment_terms_days' => '30', 'credit_limit' => '50000',
            'credit_limit_currency' => 'KES', 'tags' => 'wholesale',
        ];
    }
}
