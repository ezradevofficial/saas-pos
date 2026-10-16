<?php

namespace Modules\FixedAssets\Documents;

use Illuminate\Database\Eloquent\Model;
use Modules\FixedAssets\Models\Disposal;

/** WF-01, FA-17: `fixed_assets.disposal`, approved at the asset's place; a sale shows its proceeds in the inbox. */
class DisposalType extends AssetDocumentType
{
    public const KEY = 'fixed_assets.disposal';

    public function key(): string
    {
        return self::KEY;
    }

    protected function model(): string
    {
        return Disposal::class;
    }

    protected function kind(): string
    {
        return 'disposal';
    }

    public function documentLink(string $documentId): string
    {
        return '/fixed-assets/disposals/'.rawurlencode($documentId);
    }

    public function amountLabel(): ?string
    {
        return 'fixed_assets.workflow.fields.proceeds';
    }

    protected function amount(Model $document): ?array
    {
        return (int) $document->proceeds_minor > 0 ? ['amount_minor' => (string) $document->proceeds_minor, 'currency' => (string) $document->currency] : null;
    }
}
