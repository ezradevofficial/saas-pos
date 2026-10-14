<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Models\StoreReturn;

/**
 * GET stores/{requisitions|issues|returns}/{id} (STR-13): one document for
 * a holder of its view permission at its store (RBAC-04); anything else is
 * not found, so ids out of scope are never confirmed. Also the base of the
 * documents' action requests.
 */
class ShowDocumentRequest extends FormRequest
{
    public const VIEW = [
        Requisition::class => 'stores.requisition.view',
        Issue::class => 'stores.issue.view',
        StoreReturn::class => 'stores.return.view',
    ];

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
        return $this->route('stores_requisition') ?? $this->route('stores_issue') ?? $this->route('stores_return');
    }

    /** Whether the user holds the document's view permission at its store. */
    protected function sees(): bool
    {
        $document = $this->document();

        return app(ScopeResolver::class)->can($this->user(), self::VIEW[$document::class], Scope::location($document->location_id));
    }

    /** 403 unless the user holds $permission at the document's store. */
    protected function mayAt(string $permission): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $permission, Scope::location($this->document()->location_id));
    }
}
