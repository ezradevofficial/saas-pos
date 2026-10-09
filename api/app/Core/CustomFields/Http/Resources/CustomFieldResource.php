<?php

namespace App\Core\CustomFields\Http\Resources;

use App\Core\CustomFields\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom field definition (CF-01, CF-02). `min`/`max` are decimal
 * strings, `visible_roles`/`editable_roles` role ids (empty: everyone who
 * reaches the record), `options` [{value, label}].
 *
 * @mixin CustomFieldDefinition
 */
class CustomFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'help' => $this->help,
            'default' => $this->default_value,
            'required' => $this->required,
            'unique' => $this->is_unique,
            'min' => $this->bound('min'),
            'max' => $this->bound('max'),
            'pattern' => $this->pattern,
            'options' => array_values($this->options ?? []),
            'lookup_target' => $this->lookup_target,
            'formula' => $this->formula,
            'formula_type' => $this->formula_type,
            'visible_roles' => array_values($this->visible_roles ?? []),
            'editable_roles' => array_values($this->editable_roles ?? []),
            'show_on_pos' => $this->show_on_pos,
            'position' => $this->position,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
