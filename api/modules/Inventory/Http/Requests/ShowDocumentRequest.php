<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Models\Adjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\Transfer;

/**
 * GET inventory/{transfers|adjustments|counts}/{id}: one document for a
 * holder of its view permission where it is (a transfer: either end,
 * RBAC-04); anything else is not found, so ids out of scope are never
 * confirmed. Also the base of the documents' action requests.
 */
class ShowDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function document(): Model
    {
        return $this->route('inventory_transfer') ?? $this->route('inventory_adjustment') ?? $this->route('inventory_count');
    }

    /** Whether the user holds the document's view permission where it is. */
    protected function sees(): bool
    {
        $document = $this->document();
        $resolver = app(ScopeResolver::class);

        return match (true) {
            $document instanceof Transfer => $resolver->can($this->user(), 'inventory.transfer.view', Scope::location($document->from_location_id))
                || $resolver->can($this->user(), 'inventory.transfer.view', Scope::location($document->to_location_id)),
            $document instanceof Adjustment => $resolver->can($this->user(), 'inventory.adjustment.view', Scope::location($document->location_id)),
            $document instanceof StockCount => $resolver->can($this->user(), 'inventory.count.view', Scope::location($document->location_id)),
            default => false,
        };
    }
}
