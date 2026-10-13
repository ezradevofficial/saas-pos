<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Inventory\Models\Adjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\Transfer;

/**
 * POST inventory/transfers/{id}/dispatch|receive|cancel,
 * inventory/adjustments/{id}/cancel, inventory/counts/{id}/submit|cancel
 * and PUT inventory/counts/{id}/lines: not found unless the user sees the
 * document (ShowDocumentRequest); then the action's permission where it
 * acts (403): dispatch and cancel at a transfer's source, receive at its
 * destination; the rest at the document's location.
 *
 * Bodies: cancel `{reason}`; receive `{lines?: [{id, qty_received}],
 * loss_reason?}`; count lines `{lines: [{id, counted_qty}]}`.
 */
class DocumentActionRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        [$permission, $locationId] = $this->permission();

        return app(ScopeResolver::class)->can($this->user(), $permission, Scope::location($locationId));
    }

    public function action(): string
    {
        return (string) $this->route()->getActionMethod();
    }

    /** @return array{0: string, 1: string} */
    private function permission(): array
    {
        $document = $this->document();

        return match (true) {
            $document instanceof Transfer => match ($this->action()) {
                'receive' => ['inventory.transfer.receive', $document->to_location_id],
                'dispatch' => ['inventory.transfer.dispatch', $document->from_location_id],
                default => ['inventory.transfer.cancel', $document->from_location_id],
            },
            $document instanceof Adjustment => ['inventory.adjustment.cancel', $document->location_id],
            $document instanceof StockCount => [$this->action() === 'cancel' ? 'inventory.count.cancel' : 'inventory.count.count', $document->location_id],
        };
    }

    public function rules(): array
    {
        return match ($this->action()) {
            'cancel' => ['reason' => ['required', 'string', 'max:500']],
            'receive' => [
                'lines' => ['sometimes', 'array', 'max:500'],
                'lines.*.id' => ['required', 'uuid'],
                'lines.*.qty_received' => ['required', 'string', InventoryRules::qty()],
                'loss_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'lines' => [
                'lines' => ['required', 'array', 'min:1', 'max:2000'],
                'lines.*.id' => ['required', 'uuid'],
                'lines.*.counted_qty' => ['present', 'nullable', 'string', InventoryRules::qty()],
            ],
            default => [],
        };
    }
}
