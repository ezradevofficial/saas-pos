<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\Http\ApiException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Loyalty\Support\LoyaltyAccess;

/**
 * The module's requests (RBAC-04, RBAC-09): a record of a company is found
 * for whoever holds its view permission at that company (or beneath it);
 * anyone else gets 404, so ids out of scope are never confirmed. Seen but
 * without the action's permission: 403.
 */
abstract class LoyaltyRequest extends FormRequest
{
    protected function access(): LoyaltyAccess
    {
        return app(LoyaltyAccess::class);
    }

    /** 404 unless one of $see is held at the company; 403 unless $act is. */
    protected function authorizeAt(string $companyId, array $see, ?string $act = null): void
    {
        $user = $this->user();
        $seen = collect($see)->contains(fn (string $permission) => $this->access()->allows($user, $permission, $companyId));

        abort_unless($seen, 404);

        if ($act !== null && ! $this->access()->allows($user, $act, $companyId)) {
            throw new ApiException(403, 'forbidden', __('rbac.forbidden'));
        }
    }

    /** 403 unless one of $permissions is held somewhere (a list or a create). */
    protected function authorizeAnywhere(string ...$permissions): void
    {
        if (! $this->access()->anywhere($this->user(), ...$permissions)) {
            throw new ApiException(403, 'forbidden', __('rbac.forbidden'));
        }
    }

    /** Minor units: a string of digits that fits a bigint, never a JSON number (ADR 003). */
    protected static function minor(bool $required = true, bool $positive = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:/^\d{1,18}\z/', function (string $attribute, mixed $value, Closure $fail) use ($positive) {
            if ($positive && is_string($value) && preg_match('/^0+$/', $value) === 1) {
                $fail(__('loyalty.validation.positive'));
            }
        }];
    }

    /** Signed minor units ("-1250"), not zero. */
    protected static function signedMinor(): array
    {
        return ['required', 'string', 'regex:/^-?\d{1,18}\z/', 'not_regex:/^-?0+$/'];
    }
}
