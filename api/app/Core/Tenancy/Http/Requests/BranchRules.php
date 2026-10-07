<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared branch validation (TEN-04): codes are upper case and unique among the company's active branches. */
final class BranchRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $companyId, ?Branch $branch, bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $unique = Rule::unique('branches', 'code')
            ->where('company_id', $companyId)
            ->whereNull('archived_at');

        if ($branch !== null) {
            $unique->ignore($branch->id);
        }

        return [
            'name' => [...$required, 'string', 'max:255'],
            'code' => [...$required, 'string', 'max:20', 'alpha_dash:ascii', $unique],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function normaliseCode(FormRequest $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
    }
}
