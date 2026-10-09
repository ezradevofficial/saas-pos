<?php

namespace App\Core\CustomForms\Http\Resources;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\CustomFields\CustomFieldPresenter;
use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormAttachment;
use App\Core\CustomForms\CustomFormFiles;
use App\Core\CustomForms\CustomFormLine;
use App\Core\CustomForms\CustomFormRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom form record (CF-04, CF-05): number, place, status, the header
 * custom values the user may see (CustomFieldPresenter, RBAC-05), the
 * totals of line fields (money as {amount_minor, currency}, ADR 003), the
 * amount, and with `withDetail()` its lines, attachments and what the
 * reader may do.
 *
 * @mixin CustomFormRecord
 */
class CustomFormRecordResource extends JsonResource
{
    private bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $type = $this->type;
        $presenter = app(CustomFieldPresenter::class);

        $data = [
            'id' => $this->id,
            'type' => ['id' => $type->id, 'key' => $type->key, 'name' => $type->name],
            'number' => $this->number,
            'status' => $this->status,
            'company' => ['id' => $this->company_id, 'name' => $this->company?->name],
            'branch' => $this->branch_id === null ? null : ['id' => $this->branch_id, 'name' => $this->branch?->name],
            'location' => $this->location_id === null ? null : ['id' => $this->location_id, 'name' => $this->location?->name],
            'custom' => (object) $presenter->present($user, $type->entity(), $this->custom),
            'totals' => (object) array_map(fn ($total) => is_array($total) ? ['amount_minor' => (string) $total['amount_minor'], 'currency' => (string) $total['currency']] : $total, $this->totals ?? []),
            'amount' => $this->amount()?->jsonSerialize(),
            'created_by' => ['id' => $this->created_by, 'name' => $this->creator?->name],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
        ];

        if (! $this->detail) {
            return $data;
        }

        $access = app(CustomFormAccess::class);
        $files = app(CustomFormFiles::class);

        return [
            ...$data,
            'lines' => $type->has_lines ? $this->lines->map(fn (CustomFormLine $line) => [
                'id' => $line->id,
                'position' => $line->position,
                'custom' => (object) $presenter->present($user, $type->lineEntity(), $line->custom),
            ])->values()->all() : [],
            'attachments' => $this->attachmentFiles->map(fn (CustomFormAttachment $file) => $files->present($file, $user))->values()->all(),
            'approval_id' => ApprovalRequest::query()
                ->where('document_type', $type->documentType())
                ->where('document_id', $this->id)
                ->where('status', ApprovalRequest::PENDING)
                ->value('id'),
            'can' => [
                'edit' => $user !== null && $this->resource->isEditable() && $access->edit($user, $this->resource),
                'cancel' => $user !== null && ! $this->isArchived() && in_array($this->status, [CustomFormRecord::DRAFT, CustomFormRecord::PENDING], true) && $access->edit($user, $this->resource),
                'archive' => $user !== null && $access->manageRecord($user, $this->resource),
            ],
        ];
    }
}
