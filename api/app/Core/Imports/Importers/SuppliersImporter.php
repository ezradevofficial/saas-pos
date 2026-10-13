<?php

namespace App\Core\Imports\Importers;

use App\Core\MasterData\Parties\PartyRoles;

/** IMP-01: suppliers (parties with the supplier role, MD-01). */
class SuppliersImporter extends PartiesImporter
{
    public function key(): string
    {
        return 'suppliers';
    }

    protected function role(): string
    {
        return PartyRoles::SUPPLIER;
    }

    public function example(): array
    {
        return [
            'name' => 'Bidco Distributors', 'company' => 'Main company', 'kind' => 'organisation', 'tax_id' => 'P052345678B',
            'phones' => '+254722000111', 'emails' => 'sales@example.com', 'address_line1' => 'Enterprise Road 4', 'city' => 'Nairobi',
            'country' => 'KE', 'currency' => 'KES', 'payment_terms_days' => '45', 'tags' => 'drinks',
        ];
    }
}
