<?php

namespace Modules\Inventory\Documents;

use App\Core\Currency\Money;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Adjustment;

/**
 * WF-01, INV-10: `inventory.adjustment`, a stock adjustment with a reason,
 * posted when its flow approves it. Conditions may route on the reason,
 * the location and the value out (a loss) or in, e.g. a second approval
 * for losses above an amount.
 */
class AdjustmentType extends InventoryDocumentType
{
    public const KEY = 'inventory.adjustment';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'inventory.workflow.adjustment.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'inventory.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'inventory.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'inventory.workflow.fields.company', 'core.company'),
            FieldDefinition::enum('reason', 'inventory.workflow.fields.reason', Adjustment::REASONS),
            FieldDefinition::money('value_out', 'inventory.workflow.fields.value_out'),
            FieldDefinition::money('value_in', 'inventory.workflow.fields.value_in'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        /** @var Adjustment|null $adjustment */
        $adjustment = $this->find($documentId);

        if ($adjustment === null) {
            return [];
        }

        return [
            'location' => $adjustment->location_id,
            'branch' => $adjustment->branch_id,
            'company' => $adjustment->company_id,
            'reason' => $adjustment->reason,
            'value_out' => self::money($adjustment->value_out_minor, $adjustment->currency),
            'value_in' => self::money($adjustment->value_in_minor, $adjustment->currency),
        ];
    }

    public function viewPermission(): string
    {
        return 'inventory.adjustment.view';
    }

    public function actPermission(): string
    {
        return 'inventory.adjustment.approve';
    }

    protected function model(): string
    {
        return Adjustment::class;
    }

    protected function locationOf(Model $document): string
    {
        return $document->location_id;
    }

    protected function path(): string
    {
        return '/inventory/adjustments';
    }

    protected function flowKey(): string
    {
        return 'adjustment';
    }

    /** The summary shows the value out (what is lost), else the value in. */
    protected function amount(Model $document): ?Money
    {
        $out = self::money($document->value_out_minor, $document->currency);

        return $out !== null && ! $out->isZero() ? $out : self::money($document->value_in_minor, $document->currency);
    }

    public function amountLabel(): ?string
    {
        return 'inventory.workflow.fields.value';
    }
}
