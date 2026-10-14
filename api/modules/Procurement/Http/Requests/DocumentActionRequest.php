<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ModuleRegistry;

/**
 * The actions on one Procurement document (POST …/{id}/submit|issue|
 * publish|open|post|approve|send|cancel|award|resend|remove, GET
 * …/{id}/pdf): not found unless the user sees the document
 * (ShowDocumentRequest); then the action's permission where the document
 * is (403): `cancel` → `.cancel`; `open` → `procurement.tender.open`;
 * `approve` → `procurement.invoice.approve`; a bid's `award` and its
 * letter's email → `procurement.tender.award`; `pdf` → `procurement.po.print`;
 * everything else → the resource's `.create`.
 *
 * Bodies: cancel `{reason}`; an invoice's approve `{override_reason?}`; a
 * bid's award `{reason?}`; an RFQ's award `{selections?: {rfq line id:
 * quote line id}}`; a PO's send and a letter's email `{email?, locale?}`.
 */
class DocumentActionRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt($this->permissionAction());
    }

    public function action(): string
    {
        return (string) $this->route()->getActionMethod();
    }

    private function permissionAction(): string
    {
        return match ($this->action()) {
            'cancel' => 'cancel',
            'open' => 'open',
            'approve' => 'approve',
            'award' => $this->resource() === 'procurement.tender' ? 'award' : 'create',
            'emailLetter' => 'award',
            'pdf' => 'print',
            default => 'create',
        };
    }

    public function rules(): array
    {
        return match ($this->action()) {
            'cancel' => ['reason' => ['required', 'string', 'max:500']],
            'approve' => ['override_reason' => ['sometimes', 'nullable', 'string', 'max:1000']],
            'award' => $this->resource() === 'procurement.tender'
                ? ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]
                : ['selections' => ['sometimes', 'array', 'max:500'], 'selections.*' => ['uuid']],
            'send', 'emailLetter' => ['email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'], 'locale' => ['sometimes', 'string', 'in:en,fr']],
            default => [],
        };
    }

    /** Whether the user may approve an invoice whose match failed (PRC-15). */
    public function mayOverride(): bool
    {
        return app(ModuleRegistry::class)->isActive('procurement') && $this->mayAt('override');
    }
}
