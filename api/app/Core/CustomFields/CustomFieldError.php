<?php

namespace App\Core\CustomFields;

/**
 * A custom field value that failed its type or settings: `code` names the
 * message under `core.custom_field.errors`, `params` its replacements.
 */
final class CustomFieldError
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $code, public readonly array $params = []) {}

    public function message(CustomFieldDefinition $field): string
    {
        return __('core.custom_field.errors.'.$this->code, ['field' => $field->label, ...$this->params]);
    }
}
