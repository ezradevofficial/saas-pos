<?php

namespace Modules\Inventory\Documents;

use App\Core\Currency\Money;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\StockCount;

/**
 * WF-01, INV-09: `inventory.count`, the variance approval of a submitted
 * stock count; posted when approved. A count without variance never
 * starts a flow.
 */
class CountType extends InventoryDocumentType
{
    public const KEY = 'inventory.count';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'inventory.workflow.count.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'inventory.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'inventory.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'inventory.workflow.fields.company', 'core.company'),
            FieldDefinition::enum('kind', 'inventory.workflow.fields.kind', [StockCount::FULL, StockCount::CYCLE]),
            FieldDefinition::money('loss', 'inventory.workflow.fields.loss'),
            FieldDefinition::money('gain', 'inventory.workflow.fields.gain'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        /** @var StockCount|null $count */
        $count = $this->find($documentId);

        if ($count === null) {
            return [];
        }

        return [
            'location' => $count->location_id,
            'branch' => $count->branch_id,
            'company' => $count->company_id,
            'kind' => $count->kind,
            'loss' => self::money($count->loss_minor, $count->currency),
            'gain' => self::money($count->gain_minor, $count->currency),
        ];
    }

    public function viewPermission(): string
    {
        return 'inventory.count.view';
    }

    public function actPermission(): string
    {
        return 'inventory.count.approve';
    }

    protected function model(): string
    {
        return StockCount::class;
    }

    protected function locationOf(Model $document): string
    {
        return $document->location_id;
    }

    protected function path(): string
    {
        return '/inventory/counts';
    }

    protected function flowKey(): string
    {
        return 'count';
    }

    /** The summary shows the loss, else the gain. */
    protected function amount(Model $document): ?Money
    {
        $loss = self::money($document->loss_minor, $document->currency);

        return $loss !== null && ! $loss->isZero() ? $loss : self::money($document->gain_minor, $document->currency);
    }

    public function amountLabel(): ?string
    {
        return 'inventory.workflow.fields.variance';
    }
}
