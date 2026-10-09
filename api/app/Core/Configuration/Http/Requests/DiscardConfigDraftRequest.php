<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: POST config/{kind}/{config_document}/discard-draft {revision?}:
 * archive the draft (never deleted). With `revision`, only that draft
 * revision is discarded (409 config_changed else). The kind's edit
 * permission at the document's scope.
 */
class DiscardConfigDraftRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'edit';

    public function rules(): array
    {
        return SaveConfigRequest::revisionRules();
    }

    public function revision(): ?int
    {
        $revision = $this->validated('revision');

        return $revision === null ? null : (int) $revision;
    }
}
