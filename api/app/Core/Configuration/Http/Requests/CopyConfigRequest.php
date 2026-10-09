<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigPolicy;
use App\Core\Configuration\Models\ConfigDocument;

/**
 * LAY-06: POST config/{kind}/{config_document}/copy {scope_type, scope_id,
 * from?}: this document's published payload (`from: draft` for its draft)
 * becomes the draft of the same kind and key at another company, branch
 * or location of the tenant. Seeing this document, and the kind's edit
 * permission where the copy goes (RBAC-04).
 */
class CopyConfigRequest extends ConfigDocumentRequest
{
    use ValidatesConfigScope;

    public function rules(): array
    {
        return [
            ...$this->scopeRules($this->kind(), ConfigDocument::PLACES),
            'from' => ['sometimes', 'string', 'in:published,draft'],
        ];
    }

    /** Called after validation: the copy needs edit rights where it goes. */
    public function authorizeTarget(): void
    {
        abort_unless(app(ConfigPolicy::class)->allows($this->user(), $this->kind(), 'edit', $this->validated('scope_type'), $this->scopeId()), 403);
    }
}
