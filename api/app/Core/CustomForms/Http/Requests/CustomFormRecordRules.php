<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldValidator;
use App\Core\CustomForms\CustomFormAttachment;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormType;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\Validator as Validators;
use Illuminate\Validation\Validator;

/**
 * CF-04, CF-05: the body of a custom form record: header values (`custom`,
 * CustomFieldValidator), lines (`lines.*.custom`, the line entity's fields,
 * errors keyed `lines.<n>.custom.<key>`), attachments (files uploaded for
 * the type by the user, or already the record's) and `submit`. A money
 * line field must use one currency across the lines (its total is one
 * amount).
 */
final class CustomFormRecordRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(CustomFormType $type): array
    {
        return [
            ...CustomFieldValidator::rules(),
            'lines' => $type->has_lines ? ['sometimes', 'nullable', 'array', 'max:200'] : ['prohibited'],
            'lines.*' => ['array:custom'],
            'lines.*.custom' => ['sometimes', 'nullable', 'array'],
            'attachments' => $type->attachments ? ['sometimes', 'nullable', 'array', 'max:20'] : ['prohibited'],
            'attachments.*' => ['uuid', 'distinct'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    public static function validate(Validator $validator, CustomFormType $type, ?CustomFormRecord $record, ?User $user): void
    {
        $data = $validator->getData();
        app(CustomFieldValidator::class)->validate($validator, $type->entity(), $data['custom'] ?? null, $record, $user);

        if ($type->has_lines && is_array($data['lines'] ?? null)) {
            $currencies = [];
            $money = app(CustomFieldDefinitions::class)->active($type->lineEntity())->where('type', 'money')->pluck('key')->all();

            foreach (array_values($data['lines']) as $index => $line) {
                $custom = is_array($line) ? ($line['custom'] ?? null) : null;
                $lineValidator = Validators::make([], []);
                app(CustomFieldValidator::class)->validate($lineValidator, $type->lineEntity(), $custom, null, $user);

                foreach ($lineValidator->errors()->messages() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("lines.{$index}.{$key}", $message);
                    }
                }

                foreach ($money as $key) {
                    $currency = is_array($custom) && is_array($custom[$key] ?? null) ? ($custom[$key]['currency'] ?? null) : null;

                    if (! is_string($currency)) {
                        continue;
                    }

                    $currencies[$key] ??= $currency;

                    if ($currencies[$key] !== $currency) {
                        $validator->errors()->add("lines.{$index}.custom.{$key}", __('core.custom_form.errors.one_currency', ['currency' => $currencies[$key]]));
                    }
                }
            }
        }

        foreach (array_values($data['attachments'] ?? []) as $index => $id) {
            $ok = is_string($id) && CustomFormAttachment::query()->whereKey($id)->where('type_id', $type->id)
                ->where(fn ($q) => $q->where(fn ($n) => $n->whereNull('record_id')->where('uploaded_by', $user?->id))
                    ->when($record !== null, fn ($r) => $r->orWhere('record_id', $record->id)))
                ->exists();

            if (! $ok) {
                $validator->errors()->add("attachments.{$index}", __('core.custom_form.errors.attachment_unknown'));
            }
        }
    }
}
