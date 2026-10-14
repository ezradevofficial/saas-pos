<?php

namespace Modules\Stores\Http\Requests;

/**
 * POST stores/requisitions/{id}/cancel|close and
 * stores/issues/{id}/acknowledge: not found unless the user sees the
 * document (ShowDocumentRequest); then the action's permission at its
 * store (403): cancel `stores.requisition.cancel`, close
 * `stores.issue.create`, acknowledge `stores.issue.acknowledge`.
 *
 * Bodies: cancel and close `{reason}`; acknowledge `{note?}`.
 */
class DocumentActionRequest extends ShowDocumentRequest
{
    public const PERMISSIONS = [
        'cancel' => 'stores.requisition.cancel',
        'close' => 'stores.issue.create',
        'acknowledge' => 'stores.issue.acknowledge',
    ];

    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt(self::PERMISSIONS[$this->action()]);
    }

    public function action(): string
    {
        return (string) $this->route()->getActionMethod();
    }

    public function rules(): array
    {
        return $this->action() === 'acknowledge'
            ? ['note' => ['sometimes', 'nullable', 'string', 'max:1000']]
            : ['reason' => ['required', 'string', 'max:500']];
    }
}
