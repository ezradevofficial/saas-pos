<?php

namespace Modules\Inventory\Labels;

use App\Core\DocumentTemplates\DataSource;
use App\Core\DocumentTemplates\Samples;

/**
 * TPL-01, INV-21: the merge fields of a barcode label (`inventory.label`):
 * the item's name and code, the unit, the barcode printed (its barcode for
 * that unit, else its code), the price from the chosen price list, and the
 * batch and its expiry. Labels have no lines.
 */
class LabelSource implements DataSource
{
    public const TYPE = 'inventory.label';

    public function type(): string
    {
        return self::TYPE;
    }

    public function documentFields(): array
    {
        return [
            'document.item_name' => 'text',
            'document.item_code' => 'text',
            'document.barcode' => 'text',
            'document.unit' => 'text',
            'document.price' => 'money',
            'document.batch' => 'text',
            'document.expires_on' => 'date',
        ];
    }

    public function lineColumns(): array
    {
        return [];
    }

    public function live(): bool
    {
        return true;
    }

    public function sample(?string $country): array
    {
        $base = Samples::base(self::TYPE, $country, null);
        $currency = array_key_first($base['currencies']);
        $scale = $base['scale'];
        unset($base['scale']);

        return [...$base, 'customer' => null, 'document' => [
            'number' => 'SKU-001', 'date' => '2026-10-09', 'currency' => $currency,
            'item_name' => __('templates.sample.item_1'), 'item_code' => 'SKU-001', 'barcode' => '6161000000001', 'unit' => 'EA',
            'price' => Samples::money(250, $currency, $scale), 'batch' => 'LOT-0001', 'expires_on' => '2027-03-31',
        ], 'lines' => [], 'totals' => null, 'payments' => [], 'change' => null];
    }
}
