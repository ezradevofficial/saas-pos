<?php

namespace Modules\FixedAssets\Labels;

use App\Core\DocumentTemplates\DataSource;
use App\Core\DocumentTemplates\Samples;

/**
 * TPL-01, FA-25: the merge fields of an asset label
 * (`fixed_assets.label`): the tag (also the barcode), the name, the serial
 * number, the category, the location and the company. Labels have no
 * lines.
 */
class LabelSource implements DataSource
{
    public const TYPE = 'fixed_assets.label';

    public function type(): string
    {
        return self::TYPE;
    }

    public function documentFields(): array
    {
        return [
            'document.asset_name' => 'text',
            'document.tag' => 'text',
            'document.barcode' => 'text',
            'document.serial_number' => 'text',
            'document.category' => 'text',
            'document.location' => 'text',
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
        unset($base['scale']);

        return [...$base, 'customer' => null, 'document' => [
            'number' => 'FA-NRB-00012', 'date' => '2026-10-09', 'currency' => null,
            'asset_name' => __('fixed_assets.labels.sample_name'), 'tag' => 'FA-NRB-00012', 'barcode' => 'FA-NRB-00012',
            'serial_number' => 'SN-4471-A', 'category' => __('fixed_assets.labels.sample_category'), 'location' => __('fixed_assets.labels.sample_location'),
        ], 'lines' => [], 'totals' => null, 'payments' => [], 'change' => null];
    }
}
