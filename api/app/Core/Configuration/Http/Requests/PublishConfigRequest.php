<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: POST config/{kind}/{config_document}/publish: the draft goes
 * live. The kind's publish permission at the document's scope.
 */
class PublishConfigRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'publish';
}
