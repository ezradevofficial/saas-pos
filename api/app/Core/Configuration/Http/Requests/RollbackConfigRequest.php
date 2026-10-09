<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: POST config/{kind}/{config_document}/rollback {version}: publish
 * a copy of an earlier published version. The kind's publish permission
 * at the document's scope.
 */
class RollbackConfigRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'publish';

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }
}
