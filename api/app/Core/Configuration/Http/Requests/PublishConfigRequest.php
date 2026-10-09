<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: POST config/{kind}/{config_document}/publish {revision}: the
 * draft goes live. `revision` names the draft revision the publisher
 * reviewed; 409 config_changed when the draft has changed (or gone) since.
 * The kind's publish permission at the document's scope.
 */
class PublishConfigRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'publish';

    public function rules(): array
    {
        return SaveConfigRequest::revisionRules(required: true);
    }

    public function revision(): int
    {
        return (int) $this->validated('revision');
    }
}
