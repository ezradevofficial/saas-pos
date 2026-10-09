<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigPolicy;
use App\Core\Configuration\Models\ConfigDocument;

/**
 * A request on one document, config/{kind}/{config_document} (LAY-06). A
 * document of another kind, or one the user cannot see (RBAC-04), is not
 * found; `$action` (edit or publish) is then checked at the document's
 * scope through the kind's permissions (403).
 */
class ConfigDocumentRequest extends ConfigKindRequest
{
    /** null: reading is enough. */
    protected ?string $action = null;

    public function authorize(): bool
    {
        $kind = $this->kind();
        $document = $this->document();
        $policy = app(ConfigPolicy::class);

        abort_unless($policy->view($this->user(), $kind, $document), 404);

        return $this->action === null || $policy->allows($this->user(), $kind, $this->action, $document->scope_type, $document->scope_id);
    }

    public function document(): ConfigDocument
    {
        return $this->route('config_document');
    }
}
