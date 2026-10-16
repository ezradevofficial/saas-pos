<?php

namespace Modules\FixedAssets\Labels;

use App\Core\Audit\Auditor;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Support\Access;
use Modules\FixedAssets\Support\FaError;

/**
 * FA-25: asset labels, one document per copy, printed through the
 * template that applies at the asset's company (TPL-05). Only assets the
 * person sees and may label at their location (`asset_unknown` otherwise). Audited as
 * `fixed_assets.label.print` with what was printed.
 */
class Labels
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Access $access,
    ) {}

    /**
     * @param  list<array{asset_id: string, copies: int}>  $labels
     * @return non-empty-list<DocumentData>
     */
    public function documents(array $labels, User $by): array
    {
        $documents = [];

        foreach (array_values($labels) as $index => $label) {
            $asset = Asset::query()->with(['location', 'category', 'company'])->find($label['asset_id']);

            // Review #12: seen and printable at the asset's own place.
            if ($asset === null || ! $this->access->at($by, 'fixed_assets.asset.view', $asset) || ! $this->access->at($by, 'fixed_assets.label.print', $asset)) {
                throw FaError::make('asset_unknown', field: "labels.{$index}.asset_id");
            }

            $data = $this->data($asset, $asset->company);

            for ($copy = 0; $copy < (int) $label['copies']; $copy++) {
                $documents[] = new DocumentData(LabelSource::TYPE, $asset->id, $data, $asset->company_id, null, $asset->company?->country);
            }
        }

        $this->auditor->record('fixed_assets.label.print', null, null, [
            'labels' => array_map(fn (array $l) => ['asset_id' => $l['asset_id'], 'copies' => (int) $l['copies']], $labels),
        ]);

        return $documents;
    }

    private function data(Asset $asset, ?Company $company): array
    {
        return [
            'currencies' => [],
            'company' => $company === null ? null : [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => null, 'phone' => null, 'email' => null, 'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => null,
            'location' => null,
            'customer' => null,
            'document' => [
                'number' => (string) $asset->number,
                'date' => CarbonImmutable::now($company?->timezone ?: 'UTC')->format('Y-m-d'),
                'currency' => null,
                'asset_name' => (string) $asset->name,
                'tag' => (string) $asset->number,
                'barcode' => (string) $asset->number,
                'serial_number' => $asset->serial_number,
                'category' => $asset->category?->name,
                'location' => $asset->location?->name,
            ],
            'lines' => [],
            'totals' => null,
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];
    }
}
