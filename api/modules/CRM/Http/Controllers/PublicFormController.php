<?php

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Http\Requests\SubmitLeadFormRequest;
use Modules\CRM\Leads\LeadCapture;

/**
 * CRM-06: POST /api/v1/crm/forms/{token}, a lead from a public web form.
 * Answers `{received: true}` (202) and nothing else; a filled honeypot is
 * answered the same and stores nothing; an unknown, archived or inactive
 * form is 404. Rate-limited per IP and per token (`crm-forms`).
 */
class PublicFormController
{
    public function __invoke(SubmitLeadFormRequest $request, string $token, LeadCapture $capture): JsonResponse
    {
        $form = $capture->form($token);
        abort_if($form === null, 404);

        if (trim((string) $request->validated('website', '')) === '') {
            $values = $request->safe()->except('website');
            $missing = array_values(array_filter($form->fields['required'] ?? ['name'], fn (string $field) => trim((string) ($values[$field] ?? '')) === ''));

            if ($missing !== []) {
                throw ValidationException::withMessages(array_fill_keys($missing, [__('crm.errors.form_field_required')]));
            }

            $capture->submit($form, $values);
        }

        return response()->json(['data' => ['received' => true]], 202);
    }
}
