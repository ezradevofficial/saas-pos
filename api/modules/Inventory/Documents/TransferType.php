<?php

namespace Modules\Inventory\Documents;

use App\Core\Currency\Money;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Transfer;

/**
 * WF-01, INV-08: `inventory.transfer`, a request to move stock between two
 * locations of one company (concept note 6.3: Request → Approve →
 * Dispatch → In transit → Receive). Its flow decides it; dispatch and
 * receipt are the module's own steps after approval. A tenant wanting
 * "Transfer → Receive" publishes a flow without the approval.
 */
class TransferType extends InventoryDocumentType
{
    public const KEY = 'inventory.transfer';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'inventory.workflow.transfer.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('from_location', 'inventory.workflow.fields.from_location', 'core.location'),
            FieldDefinition::reference('to_location', 'inventory.workflow.fields.to_location', 'core.location'),
            FieldDefinition::reference('branch', 'inventory.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'inventory.workflow.fields.company', 'core.company'),
            FieldDefinition::money('value', 'inventory.workflow.fields.value'),
            FieldDefinition::number('lines', 'inventory.workflow.fields.lines'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        /** @var Transfer|null $transfer */
        $transfer = $this->find($documentId);

        if ($transfer === null) {
            return [];
        }

        return [
            'from_location' => $transfer->from_location_id,
            'to_location' => $transfer->to_location_id,
            'branch' => $transfer->branch_id,
            'company' => $transfer->company_id,
            'value' => $this->amount($transfer),
            'lines' => $transfer->lines()->count(),
        ];
    }

    public function viewPermission(): string
    {
        return 'inventory.transfer.view';
    }

    public function actPermission(): string
    {
        return 'inventory.transfer.approve';
    }

    protected function model(): string
    {
        return Transfer::class;
    }

    protected function locationOf(Model $document): string
    {
        return $document->from_location_id;
    }

    protected function path(): string
    {
        return '/inventory/transfers';
    }

    protected function flowKey(): string
    {
        return 'transfer';
    }

    protected function amount(Model $document): ?Money
    {
        return self::money($document->value_minor, $document->currency);
    }
}
