<?php

namespace Modules\Manufacturing\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * POST manufacturing/bom-versions/{id}/publish (MFG-03): not found unless
 * the user sees the BOM; `manufacturing.bom.manage` in its company (403);
 * `{effective_from?}` (ISO 8601; default now).
 */
class PublishVersionRequest extends ShowBomRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->manages();
    }

    public function rules(): array
    {
        return ['effective_from' => ['sometimes', 'nullable', 'date']];
    }

    public function effectiveFrom(): ?CarbonImmutable
    {
        return $this->filled('effective_from') ? CarbonImmutable::parse((string) $this->validated('effective_from')) : null;
    }
}
