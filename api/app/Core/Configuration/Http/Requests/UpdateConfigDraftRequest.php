<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: PUT config/{kind}/{config_document}/draft {payload, name?,
 * revision?}: save the draft (created when there is none). `revision` is
 * the draft revision the edit started from (null or absent: no draft);
 * 409 config_changed when the draft has moved on. A draft may have
 * problems; the answer lists what blocks publishing. The kind's edit
 * permission at the document's scope.
 */
class UpdateConfigDraftRequest extends ConfigDocumentRequest
{
    protected ?string $action = 'edit';

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            ...SaveConfigRequest::payloadRules($this->kind()),
            ...SaveConfigRequest::revisionRules(),
        ];
    }

    public function revision(): ?int
    {
        $revision = $this->validated('revision');

        return $revision === null ? null : (int) $revision;
    }

    /** The payload as sent (validated() keeps only the keys named in rules()). */
    public function payload(): array
    {
        return (array) $this->input('payload');
    }
}
