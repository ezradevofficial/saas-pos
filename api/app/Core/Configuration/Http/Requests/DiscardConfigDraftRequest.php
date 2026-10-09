<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: POST config/{kind}/{config_document}/discard-draft: archive the
 * draft (never deleted). The kind's edit permission at the document's scope.
 */
class DiscardConfigDraftRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'edit';
}
