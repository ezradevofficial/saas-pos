<?php

namespace App\Core\CustomForms\Http\Resources;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom form type (CF-04): its key, name (typed once), the entities of
 * its fields, its number type, workflow, lines and attachments settings.
 * The role allow-list is shown to those who manage types; `can` tells the
 * reader what they may do with its records anywhere (the API checks again
 * at each record's place).
 *
 * @mixin CustomFormType
 */
class CustomFormTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(CustomFormAccess::class);
        $user = $request->user();
        $manages = $access->manages($user);

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'entity' => $this->entity(),
            'line_entity' => $this->lineEntity(),
            'layout_key' => $this->layoutKey(),
            'document_type' => $this->documentType(),
            'number_type' => $this->number_type,
            'workflow' => $this->workflow,
            'has_lines' => $this->has_lines,
            'line_fields' => array_values($this->line_fields ?? []),
            'attachments' => $this->attachments,
            'role_ids' => $manages ? array_values($this->role_ids ?? []) : null,
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'can' => [
                'view' => $user !== null && $access->anywhere($user, $this->resource),
                'create' => $user !== null && ! $this->isArchived() && $access->anywhere($user, $this->resource, [CustomFormAccess::CREATE]),
                'manage' => $manages,
            ],
        ];
    }
}
