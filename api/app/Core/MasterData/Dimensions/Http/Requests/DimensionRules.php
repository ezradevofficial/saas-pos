<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Dimension;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-05 dimension validation. The code is unique (case-insensitive) among
 * the company's active rows of that kind; the parent is an active row of
 * the same kind and company, not the row itself or beneath it; the owner
 * (APR-02) is an active user who can view the company.
 */
final class DimensionRules
{
    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,29}\z/';

    /**
     * @param  class-string<Dimension>  $model
     * @return array<string, list<mixed>>
     */
    public static function rules(string $model, string $companyId, ?Dimension $dimension): array
    {
        $required = $dimension === null ? ['required'] : ['sometimes', 'required'];
        $table = (new $model)->getTable();
        $unique = Rule::unique($table, 'code')->where('company_id', $companyId)->whereNull('archived_at');

        if ($dimension !== null) {
            $unique->ignore($dimension->id);
        }

        return [
            'code' => [...$required, 'string', 'regex:'.self::CODE_PATTERN, $unique],
            'name' => [...$required, 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('archived_at')],
            'owner_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
        ];
    }

    public static function normalise(FormRequest $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => trim($request->input('code'))]);
        }
    }

    public static function validate(Validator $validator, array $input, Company $company, ?Dimension $dimension): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $parentId = $input['parent_id'] ?? null;

        if ($dimension !== null && $parentId !== null && $dimension->isAncestorOf($parentId)) {
            $validator->errors()->add('parent_id', __('core.dimension.parent_cycle'));
        }

        $ownerId = $input['owner_user_id'] ?? null;

        if ($ownerId !== null && ! self::canOwn(User::query()->findOrFail($ownerId), $company)) {
            $validator->errors()->add('owner_user_id', __('core.dimension.owner_no_access'));
        }
    }

    /** APR-02: an owner approves for the company, so they must be able to view it. */
    public static function canOwn(User $user, Company $company): bool
    {
        return app(Visibility::class)->reaches($user, 'core.company.view', $company);
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'code.regex' => __('core.dimension.code_invalid'),
            'code.unique' => __('core.dimension.code_taken'),
            'parent_id.exists' => __('core.dimension.parent_other_company'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return collect(['code', 'name', 'parent_id', 'owner_user_id'])
            ->mapWithKeys(fn (string $key) => [$key => __("core.dimension.attributes.{$key}")])
            ->all();
    }
}
